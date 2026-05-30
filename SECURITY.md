# Security Policy

## Supported Versions

This project is currently in active development (Phase 1). Only the latest commit on `main` is supported.

| Version | Supported |
|---------|-----------|
| main    | ✓         |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Report vulnerabilities privately via GitHub's Security Advisory feature:
**Security → Report a vulnerability** on this repository.

Include:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (optional)

You can expect an acknowledgement within 48 hours.

## Scope

This repository contains a Shopware 6 plugin for internal service-to-service order API integration.

**In scope:**
- Authentication and authorization bypass
- Injection vulnerabilities (SQL, command)
- Sensitive data exposure via API responses
- Insecure direct object reference (IDOR)

**Out of scope:**
- Vulnerabilities in Shopware core (report to [Shopware](https://www.shopware.com/en/security/))
- Vulnerabilities requiring physical access to the server
- Social engineering

## Security Design Notes

- API routes require Shopware OAuth2 Bearer token (Admin API scope)
- No credentials are stored in this repository — see `.env.test.dist` for required configuration
- The plugin runs inside the Shopware container on a private network — not exposed directly to the internet
