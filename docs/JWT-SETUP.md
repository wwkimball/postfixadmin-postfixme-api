# JWT (JSON Web Tokens) Configuration

This PostfixMe RESTful API for PostfixAdmin extends the PostfixAdmin authentication system to add JSON Web Token.  This
enables a remote device to securely maintain a long-lived login session.  As such, mobile devices can resume (refresh)
login sessions when the mobile app is rarely used.

This document explores what JWTs are so that administrators considering PostfixMe can get a clear understanding of how
this API protects their PostfixAdmin installation while still enabling mobile app access.

In order to use the PostfixMe API, you must configure and use JWT.  Configuration is as simple as generating a
crypographic key pair (private and public) and adding them to either the default location or telling PostfixMe where to
find your key pair files.  Continue reading for step-by-step instructions.

## What is JWT?

JWT (JSON Web Tokens) is a compact, URL-safe method for representing claims (pieces of information) between two parties.
In this project, it's used as a **stateless authentication mechanism** for the PostfixMe API.

### How JWT Works

A JWT consists of three parts separated by dots (`.`):

1. **Header**:  Contains metadata about the token (type: "JWT", algorithm used: "RS256")
2. **Payload**:  Contains the claims (user ID, roles, expiration time, etc.)
3. **Signature**:  Cryptographic signature proving the token hasn't been tampered with

Example JWT:

```text
eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyQGV4YW1wbGUuY29tIiwiaWF0IjoxNjA0Njc2ODAwLCJleHAiOjE2MDQ2ODA0MDB9.signature...
```

## Why Use JWT?

### In the PostfixMe API Context

1. **Stateless Authentication**:  The server doesn't need to store session information.  Each token is self-contained
   and verifiable.

2. **Mobile-Friendly**:  Mobile apps (like PostfixMe iOS) can securely store and send tokens without complex session
   management.

3. **Secure Communication**:  RS256 (RSA with SHA-256) uses public/private key cryptography:
   - The **private key** signs tokens (only the server has this)
   - The **public key** verifies tokens (clients can verify without the private key)

4. **Token Types**:
   - **Access Tokens**:  Short-lived (15 minutes default) - used for API requests
   - **Refresh Tokens**:  Long-lived (5 years default) - used to obtain new access tokens when they expire

5. **Security Benefits**:
   - Prevents token tampering (invalid signature = rejected token)
   - Time-bound tokens limit damage if compromised
   - Refresh tokens can be revoked without updating the API

## RS256 Algorithm Explanation

RS256 (RSA Signature with SHA-256) uses asymmetric cryptography:

- **RSA**:  Public-key cryptography algorithm based on mathematical properties of large prime numbers
- **SHA-256**:  Secure Hash Algorithm producing a 256-bit fingerprint of the data
- **Why RSA?**:  Different parties can verify the signature using only the public key, without access to the private key

## Generating JWT Secret Files

The PostfixMe API requires two files for JWT token signing and verification:

- **Private Key File**:  Used by the server to **sign** tokens
- **Public Key File**:  Used by clients and the server to **verify** tokens

### Quick Generation (Recommended)

Generate the key pair using OpenSSL:

```bash
# Generate private key (2048-bit RSA key)
openssl genrsa -out pfme_jwt_private_key.txt 2048

# Extract public key from private key
openssl rsa -in pfme_jwt_private_key.txt -pubout -out pfme_jwt_public_key.txt

# Set proper permissions (restrict access to private key)
chmod 600 pfme_jwt_private_key.txt
chmod 644 pfme_jwt_public_key.txt
```

### What Each Command Does

1. **`openssl genrsa -out pfme_jwt_private_key.txt 2048`**
   - `openssl genrsa`:  Generate RSA private key
   - `2048`:  Key size in bits (widely compatible; use 4096 if you prefer stronger keys)
   - `-out`:  Output file location

2. **`openssl rsa -in pfme_jwt_private_key.txt -pubout -out pfme_jwt_public_key.txt`**
   - `openssl rsa`:  RSA key operations
   - `-in`:  Input private key file
   - `-pubout`:  Extract and output the public key portion
   - `-out`:  Output file location

3. **`chmod` commands**: Set appropriate file permissions
   - Private key:  `600` (read/write for owner only)
   - Public key:  `644` (readable by everyone, writable only by owner)

### Verifying Generation

Check that files were created correctly:

```bash
# Verify private key exists and has correct format
head -1 pfme_jwt_private_key.txt
# Should output: -----BEGIN RSA PRIVATE KEY-----

# Verify public key exists and has correct format
head -1 pfme_jwt_public_key.txt
# Should output: -----BEGIN PUBLIC KEY-----

# Check key size
openssl rsa -in pfme_jwt_private_key.txt -text -noout | grep "Private-Key"
# Should show: Private-Key: (2048 bit, RSA)
```

## Docker Secret Management

The Docker Compose configuration references these files:

```yaml
secrets:
  pfme_jwt_private_key:
    file: ./pfme_jwt_private_key.txt
  pfme_jwt_public_key:
    file: ./pfme_jwt_public_key.txt
```

At runtime, Docker makes these available inside containers at:

- `/run/secrets/pfme_jwt_private_key` (private key)
- `/run/secrets/pfme_jwt_public_key` (public key)

The PHP API (`pfme/api/config/config.php`) reads these file paths and uses them for token operations.

## Security Considerations

### Private Key Protection

**CRITICAL**:  Your private key must be protected:

- **Never** commit to version control (add to `.gitignore`)
- **Never** share publicly or email
- **Never** include in Docker images (use Docker secrets instead)
- Restrict file permissions to owner only (`600`)
- Consider storing in a secrets management system (HashiCorp Vault, AWS Secrets Manager, etc.) in production

### Key Rotation

If your private key is compromised:

1. Generate a new key pair immediately
2. Update the private key file with the new private key
3. Update the public key file with the new public key
4. Restart the API service
5. Existing access tokens will become invalid (this is intentional)
6. Mobile clients must log in again to get new tokens

### Deployment Recommendations

**Development**:  Keys in local repository (acceptable for local testing only)

**Staging/Production**:

1. Generate keys once
2. Store in secure location outside repository
3. Inject into Docker environment at deployment time via:
   - Docker secrets
   - Environment variables
   - Secrets management system (recommended)
4. Rotate keys periodically (per your own security policy)

## Troubleshooting

### "undefined secret pfme_jwt_private_key" Error

This error occurs during Docker Compose configuration when the secret files don't exist.

**Solution**:  Generate the keys using the quick generation commands above.

### Permission Denied When Reading Keys

If Docker containers can't read the keys:

```bash
# Ensure public key is readable
chmod 644 pfme_jwt_public_key.txt

# Verify ownership
ls -l pfme_jwt_*.txt
```

### Keys Not Persisting After Container Restart

The keys should persist because they're mounted from the host filesystem. If they're disappearing:

1. Check that files still exist on the host
2. Verify Docker volume mounts in `docker-compose.yaml`
3. Confirm the key files haven't been deleted

## Additional Resources

- [JWT.io](https://jwt.io) - JWT introduction and debugger
- [RFC 7519](https://tools.ietf.org/html/rfc7519) - JWT specification
- [RFC 7518 Section 3.3](https://tools.ietf.org/html/rfc7518#section-3.3) - RS256 algorithm specification
- [OpenSSL Documentation](https://www.openssl.org/docs/) - RSA key generation details
- [OWASP JWT Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/JSON_Web_Token_for_Java_Cheat_Sheet.html)

## Token Lifecycle in PostfixMe

1. **Login**:  User provides mailbox and password to PostfixMe iOS app
2. **Token Issuance**:  API validates credentials, generates access token + refresh token
3. **API Requests**:  iOS app includes access token in API request headers
4. **Token Verification**:  API verifies token signature using public key
5. **Token Expiration**:  When access token expires (15 minutes), iOS app uses refresh token to get new one
6. **Logout**:  iOS app discards tokens; tokens become worthless if already expired

This flow keeps communication stateless and secure without requiring the server to maintain session databases.
