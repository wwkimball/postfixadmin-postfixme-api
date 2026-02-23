# PostfixMe (pfme) — Project Requirements and Implementation Prompt

This document is an AI-style implementation prompt for PostfixMe (pfme).
PostfixMe pairs a native iOS app with a PHP API that integrates with
PostfixAdmin to allow users to manage their email aliases.

Use this README to scaffold, implement, and test the project.

---

## Overview

- Name: PostfixMe (pfme)
- Components:
  - `pfme-swift`: native iOS app in Swift (SwiftUI preferred).
  - `pfme-api`: PHP API exposing a secure JSON REST interface on the
    PostfixAdmin DB schema.
- Deployment: both components run as Docker services and join existing
  PostfixAdmin networks as appropriate.

## High-level constraints

- Authentication: users authenticate with mailbox credentials only. The API
  must validate credentials against the PostfixAdmin DB and respect its
  password hashing schemes.
- Integration: The API container should extend or layer upon the upstream
  PostfixAdmin image to leverage existing classes for password verification
  and configuration options, ensuring multi-version compatibility.
- TLS: the Swift app must refuse non-TLS connections except for `localhost`
  or `127.0.0.1` in development. The API must require TLS; accept a trusted
  TLS header from a proxy only when the proxy is in a configured CIDR.
- Domain handling: do not display or allow domain edits except during login.
  Elsewhere show only local-parts.
- UI Accessibility: The iOS app must honor user accessibility settings
  (dynamic type/scaling, high contrast) and offer specific theming options.
- Upstream integrity: avoid changing PostfixAdmin core sources; add an
  additive API layer instead.

## Filesystem layout (actual implementation)

- `pfme/api`
  - `src/` (Controllers, Services, Models, Middleware)
  - `public/index.php`
  - `config/` (env mapping, trusted proxies, JWT config)
  - `tests/` (unit and integration)
  - `README.md`
  - `composer.json`
- `pfme/ios`
  - `PostfixMe.xcodeproj` (native Xcode project)
  - `Sources/PostfixMe/` (Swift source files)
    - `PostfixMeApp.swift` (app entry point)
    - `ContentView.swift` (main navigation)
    - `Models/` (APIModels, Theme)
    - `Views/` (LoginView, AliasListView, AliasDetailView, AliasFormViews, SettingsView, ConnectionSettingsView)
    - `Services/` (APIService, AuthenticationManager, KeychainService)
    - `Assets.xcassets/AppIcon.appiconset/` (app icons with Glass UI styling)
  - `tools/generate_app_icons.py` (Pillow-based icon generator)
  - `Info.plist`
  - `.build/` (Xcode derived data, gitignored)
- `docker/pfme-php-api`
  - `Dockerfile` (copies artifacts from `pfme/api`)
  - `README.md`
- `docker/docker-compose.development.yaml` (add necessary compose overrides for local dev)
- `docker/docker-compose.qa.yaml` (create; override compose for CI test automation)
- `.vscode/tasks.json` (build and simulator tasks for iOS 18 and iOS 26)

Follow the repo secret pattern (`*_FILE` -> `/run/secrets/...`) and map
secrets to env vars using `lib/docker/functions/` helpers.  Never modify
anything under `lib/` because it is a third-party library.

## Configuration and secrets

- Utilize existing PostfixAdmin secrets where already available; do not
  duplicate them (i.e.:  DB connectivity).
- Create only novel secret names like: `PFME_JWT_PRIVATE_KEY_FILE`,
  `PFME_JWT_PUBLIC_KEY_FILE`.
- TLS/proxy: `TRUSTED_PROXY_CIDR` and `TRUSTED_TLS_HEADER_NAME`
  (example: `X-Forwarded-Proto`).
- Document required env vars and secrets in [docker/pfme-php-api/README.md](../../../docker/pfme-php-api/README.md).

## API: Design, Endpoints, and Behavior

- Base path: `/api/v1`
- JSON only. All responses use `Content-Type: application/json`.
- Pagination: `page` (1-based) and `per_page`. Responses include `meta`
  with `page`, `per_page`, `total`, and `total_pages`.
- Errors: structured as:
  `{ "code": "string", "message": "string", "details": { ... } }`

### Auth endpoints

1. `POST /api/v1/auth/login`
   - Request: `{ "mailbox": "user@example.com", "password": "..." }`
   - Behavior:
     - Validate mailbox credentials against PostfixAdmin DB using the same
       verification logic used by PostfixAdmin.
     - On success: issue an access token and a refresh token and return
       minimal user metadata.
     - On failure: return `401` with a generic message.
   - Security: rate-limit, lockout after failures, and audit log attempts.

2. `POST /api/v1/auth/logout` — revoke the current token server-side.

3. `POST /api/v1/auth/refresh` — rotate tokens; require server-side
   revocation support for refresh tokens.

4. `POST /api/v1/auth/change-password`

- Request: `{ "current_password": "...", "new_password": "..." }`
  - Behavior:
    - Requires a valid access token (authenticated).
    - Verify `current_password` against the mailbox credentials.
    - Enforce password policy (min length, complexity, and disallow reuse if available).
    - Update mailbox password in PostfixAdmin using the same hashing scheme configuration.
    - On success: return `204 No Content`.
    - On failure: return `401` for invalid current password; `400` for policy violations.
  - Security: rate-limit attempts, audit log password changes, delete or revoke all active session, access, refresh, and other authentication tokens for the mailbox on success, and require re-login.

#### Password policy (minimum)

- Minimum length: 10 characters (configurable: 8-64 chars recommended)
- Must include at least one space (passphrase format) (configurable)
- Must include at least one grammar symbol (punctuation: . , ! ? ; : ' " - ( ) [ ] { } @ # $ % ^ & * ) (configurable)
- Must not match the current password
- Reject passwords found in common breached lists (if available)

**Note:** Password policy is configurable via environment variables (`PFME_PASSWORD_MIN_LENGTH`, `PFME_PASSWORD_REQUIRE_SPACE`, `PFME_PASSWORD_REQUIRE_GRAMMAR_SYMBOL`) to support different organizational security requirements. See [README.md](../README.md) for configuration details.

### Token model (recommendation)

- Use RS256-signed JWTs for short-lived access tokens (for example 15m).
- Use opaque server-stored refresh tokens (default 5 years, configurable) for
  revocation.
- Include `jti`, `iat`, `exp`, `sub`, and `aud` claims.
- Scope refresh tokens to device; persist them in iOS Keychain.
- Maintain a revocation list in the DB for immediate revocation.  To create
  necessary DB schema, utilize the existing schema automation platform:
  - Script logic:  `lib/database/schema/mysql.sh`
  - Schema definition files:  `schema/mysql/` (use present-day `YYYY/MM`
    directory and file naming)

### Alias endpoints (authenticated)

1. `GET /api/v1/aliases`
   - Query: `q` (local-part search), `page`, `per_page`, `sort`, `status`.
   - Returns aliases that forward to the authenticated user's mailbox only.

2. `POST /api/v1/aliases`
   - Request: `{ "local_part": "alias", "destinations": ["user@host", ...] }`
   - Behavior: create an alias that forwards to the list of destinations.
   - Constraints:
     - "Alias Name": input strictly as username portion (local-part); domain is assumed from auth context.
     - Destinations: Must include the authenticated user's mailbox (mandatory).
     - Validation: only allow destinations allowed by policy.

3. `PUT /api/v1/aliases/{id}`
   - Request: `{ "local_part": "...", "destinations": [...], "active": boolean }`
   - Behavior: rename alias, update destination list, or toggle active status.
   - Validation: same constraints as creation.

4. `DELETE /api/v1/aliases/{id}`
   - Removal logic: Alias must be disabled (inactive) before deletion.
   - API should enforce this state or return 409 Conflict if active.

### Permissions and scope

- Verify every alias operation is scoped to the authenticated mailbox.
- Admin or privileged endpoints are out of scope for the mobile app.

## UI / UX guidance (iOS)

### Technical Requirements

- **Swift Version**: Swift 6 with strict concurrency checking enabled
- **Minimum Deployment Target**: iOS 18.0
- **UI Framework**: SwiftUI
- **Architecture**: MVVM with `ObservableObject` and `@EnvironmentObject` for state management
- **Data Persistence**: iOS Keychain for credentials and tokens
- **Networking**: URLSession with async/await

### App Icon

- **Style**: Apple Glass UI aesthetic
- **Design**: Navy `@` symbol (RGB 20, 40, 90) with left-to-right gradient fade (0%→100% opacity)
- **Background**: Warm cream gradient (RGB 252, 248, 235 at top fading to RGB 234, 230, 220 at bottom)
- **Effects**: Raised 3D appearance with subtle shadow (bottom-right) and highlight (top-left) for depth
- **Corners**: Rounded with 18% corner radius matching iOS standard
- **Generation**: Python script using Pillow (`pfme/ios/tools/generate_app_icons.py`)
- **Sizes**: 1024×1024, 180×180, 120×120, 167×167, 152×152, 76×76

### Navigation & Layout

- Use a friendly, straightforward native iOS interface.
- "Back" and "Home" buttons must be prominent in the upper-left where depth implies it.
- Honor iOS accessibility settings (Dynamic Type, high contrast, etc.).
- Tab-based navigation for main sections (Aliases, Settings)

### Customizable Options

The app must support user customization for Theme and Connection.

#### 1. Themes

Support the following themes with user-selectable options:
1.1. System: Follows device light/dark mode (no watermark).
1.2. Light: Clean light palette.
1.3. Dark: Clean dark palette.
1.4. Amber: Warm amber palette.
1.5. Beach: Sand and teal palette.
1.6. Crimson: Deep red palette.
1.7. Forest: Dark green palette.
1.8. Lavender: Soft purple palette.
1.9. Midnight: Blue-black palette.
1.10. Mint: Fresh green palette.
1.11. Ocean: Cool blue palette.
1.12. Pro: VS Code-inspired palette.
1.13. Sakura: Gentle pink palette with emoji watermark.
1.14. Space: Deep space palette with emoji watermark.
1.15. Viest: Black/green palette with emoji watermark.

**Watermark Behavior**:

- Emoji watermarks are rendered for Sakura, Space, Viest, and other themed palettes.
- System/Light/Dark/Pro themes do not render a watermark.
- Watermarks are positioned in the top-right and use low-opacity styling to avoid obscuring content.

#### 2. Connection

- Mail account credentials (stored in Keychain).
- Note: PostfixAdmin Server URL has been moved to the Login screen as it is a required setting.

### Feature Flows

#### Login Screen

The login screen must include:

- **PostfixAdmin Server URL**: Required text field for the API server endpoint (e.g., `https://mail.example.com`)
- **Mailbox**: Email address field (e.g., `user@example.com`)
- **Password**: Secure text entry
- Credentials and server URL are persisted to Keychain on successful login
- Server URL field is always visible and editable (not hidden in Settings)

#### Alias Management (Add/Edit)

The Add and Edit screens share a similar form:

- **Alias Name**: Text entry for local-part only (domain hidden).
- **Destinations**:
  - Multi-select list of potential mailbox addresses on the same domain.
  - User's own mailbox is ALWAYS selected and locked (cannot be removed).
  - Free-form text filter to find other existing aliases/mailboxes (local-part search).
  - Read-only list of currently selected destinations with "Remove" buttons.
- **Constraints**:
  - Domain name is implied from the logged-in user.
  - Save button triggers the API call.

#### Search & Browse

- **Search**: Simple text search against user's aliases.
- **Browse**: Paginated, sorted list.
- **Actions**: Rename, Disable/Enable, Delete.
- **Delete Logic**: The delete option is visible but disabled unless the alias is already disabled. Deletion requires double confirmation.

##### Search Bar Implementation: iOS Version-Aware Design

To address UX challenges when users have hundreds of aliases, the search bar placement is optimized for each iOS version using platform-native conventions:

- **iOS 18**: Top sticky search bar
  - The search bar is pinned below the "Email Aliases" navigation title
  - Remains visible while scrolling through the list
  - Provides familiar top-down navigation flow aligning with iOS 18 conventions
  - Separated from list content with a subtle border for visual hierarchy

- **iOS 26**: Bottom floating search bar
  - The search bar floats at the bottom of the screen, overlaying the list
  - Designed for thumb accessibility on larger devices (iPhone Pro models)
  - Uses shadow elevation to distinguish from list content
  - List content scrolls uninterrupted behind the floating bar
  - Aligns with iOS 26 patterns seen in native Apple apps (Mail, Messages, Safari)

**Technical Implementation**:

- Uses Swift's `@available(iOS 26, *)` compile-time version branching (zero runtime overhead)
- Both layouts share a unified `listContent` component to minimize code duplication
- Search state and filtering logic remain identical across versions
- Clear/cancel button in the search field for quick reset (always visible when text is entered)

**UX Rationale**:

- Solves the discoverability problem: users can search for specific aliases without scrolling back to the top
- Respects each OS version's design language and user expectations
- Maintains accessibility (search bar never hidden from keyboard navigation or VoiceOver)
- Supports pagination transparently (search works across paginated results)

- Error handling: minimal, actionable messages for recoverable errors.
  - Network/API unreachable: "Server offline; try again later."
  - Authentication failures: "Invalid credentials. Please check your mailbox and password."
  - Validation errors: Display specific field errors from API
  - Generic failures: "Something went wrong. Please try again."

#### Settings View (from Alias List)

- Add a "Change Mailbox Password" action within Settings.
- Flow:
  1. User taps "Change Mailbox Password".
  2. Prompt for current password and new password (confirm new password).
  3. Call `POST /api/v1/auth/change-password` with `{ current_password, new_password }`.
  4. On success, clear access/refresh tokens, update Keychain-stored password, and force re-login.
  5. On failure, show specific error and do not update Keychain.
- UX requirements:
  - Use secure text entry fields for all password inputs.
  - Disable submit until all fields are non-empty and new password confirmation matches.
  - Show inline validation errors returned by API (policy violations).
  - If `401 invalid current password`, show "Current password is incorrect."

## Security and infrastructure

- Do not store plaintext passwords; use PostfixAdmin verification logic.
- TLS termination must be external to containers.
- Validate trusted proxy headers only from configured CIDRs.
- Audit login, token rotation, and alias changes.

## Testing and CI

### Backend (PHP API)

1. Add Docker secrets into `docker/secrets/` or use `.env` for local dev.
2. Start PostfixAdmin and DB services from the existing compose file.
3. Start the entire service stack:

   ```sh
   ./build.sh --clean --start
   ```

### Completed (iOS App)

- `pfme/ios/` native Xcode project with SwiftUI implementation
  - Core views: Login, Alias List, Alias Detail, Alias Forms, Settings
  - Services: API client, Authentication manager, Keychain integration
  - Models: API models with Sendable conformance, Theme system
  - Glass UI app icon with Python generator script
  - VS Code build and simulator tasks
  - Swift 6 strict concurrency compliance

### Completed (Backend & Infrastructure)

- `pfme/api` implementation with tests and README
- `docker/pfme-php-api` implementation with Docker image build

- `docker/docker-compose.development.yaml` for local pfme development
- `docker/docker-compose.qa.yaml` for manual and automated (CI) testing
- PHPUnit test suite
- Theme watermark implementations

### Pending

- CI updates and deployment notes
- Swift UI tests

  cd pfme/ios
  python3 -m venv .venv
  source .venv/bin/activate
  pip install Pillow

**Generate App Icons** (when needed):

   ```sh
   cd pfme/ios
   source .venv/bin/activate
   python tools/generate_app_icons.py
   ```

**Build and Run**:

- **Via VS Code Tasks**:
  - `Build PostfixMe iOS App (Debug - HTTP Fallback Enabled)`
  - `Build PostfixMe iOS App (Release - HTTPS Enforcement)`
  - `Test PostfixMe on iOS 18 iPhone Simulator`
  - `Test PostfixMe on iOS 26 iPhone Simulator`
  - `Test PostfixMe on iOS 18 iPad Simulator`
  - `Test PostfixMe on iOS 26 iPad Simulator`

- **Via Command Line**:

```sh
cd pfme/ios
xcodebuild -project PostfixMe.xcodeproj -scheme PostfixMe \
  -sdk iphonesimulator -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath .build build
```

- **Via Xcode**: Open `pfme/ios/PostfixMe.xcodeproj` in Xcode

**Simulator Configuration**:

- iOS 18: iPhone 16 Pro (UDID: 0F9E6954-A7A0-41FB-8C0E-C4469B8F5D54)
- iOS 26: iPhone 16 Pro (UDID: 6F8AEA36-B5CC-441F-951A-EB7A8FC63E2E)

Run the iOS app in simulator against a dev endpoint (use `localhost`
   for simulator TLS exceptions).

When finished, stop the backend stack:

   ```sh
   ./stop.sh
   ```

### Common Issues

- **Swift Concurrency**: Ensure all `@Observable` classes and network calls properly use `@MainActor` where needed
- **Keychain Access**: Simulator keychain is isolated per device; credentials won't transfer between simulators
- **TLS Exceptions**: localhost/127.0.0.1 are automatically exempt from TLS requirements in development builds
- **Icon Caching**: If icons don't update, clean build folder and restart simulator

## Running locally (dev)

1. Add Docker secrets into `docker/secrets/` or use `.env` for local dev.
2. Start PostfixAdmin and DB services from the existing compose file.
3. Start the entire service stack:

   ```sh
   ./build.sh --clean --start
   ```

4. Run the iOS app in simulator against a dev endpoint (use `localhost`
   for simulator TLS exceptions).
5. Test and troubleshoot the API using live edits to files in `pfme/api`
   (bind-mounted into the pfme-php-api container)
6. Develop and debug the iOS app in XCode.
7. When finished, stop the stack:

   ```sh
   ./stop.sh
   ```
