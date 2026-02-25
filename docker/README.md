# Example Docker Compose Stack for PostfixMe API

This is only an example.  This `docker` directory does not represent a single
authoritative means of building or deploying this project; it is only one
possible example.  In fact, the PostfixAdmin container represented here is
deliberately *not* fully functional and the NGINX reverse proxy is *not*
sufficiently hardened.  You should not use this example as-is for your own
production deployments.  That said, you are welcome to develop your own
fully-functional concrete implementation based on this example.

Unchanged, this Docker-based example does not produce publishable artifacts.  Do
not publish or deploy these artifacts as-is.

## MariaDB Examples

This example shows a possible concrete implementation of the PostfixMe API
project based on a MariaDB database layer.  You could use PostgreSQL or SQLite
in your own concrete implementation.

This example shows how you could implement the PostfixMe API's database schema
requirements using a database schema management tool; this example uses William
Kimball's shell script library, which provides generally useful
[database schema automation helpers](https://github.com/wwkimball/shell-script-lib/tree/master/database).

## Development, QA, and Production

Three example deployment stages are presented:

- Development presents the ability to edit the API code with your changes being
  immediately reflected by the running `pfme-api` container.  Seed data is also
  loaded automatically so you can test the API endpoints.
- QA presents the ability to run the comprehensive PHP tests against the API.
  The same seed data used by the development environment is loaded for these
  tests.
- Production shows a sealed deployment scenario of the PostfixMe API using an
  externally-managed database layer.

### Development Quick Start

This project utilizes several `.env` files, secrets, and keys.  To quickly
generate all of the necessary files, you may run the
[Generate Sample Secrets script](docker/scripts/generate-sample-secrets.sh),
which will create a minimal set of the necessary files; all secrets will be
randomized.  This script is idempotent, so it is safe to run repeatedly; it will
not overwrite any files which already exist.
