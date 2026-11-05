# Security policy

## Supported versions

Security fixes go into the latest release only.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through GitHub's [private vulnerability reporting](https://github.com/pixelersagency/leiho/security/advisories/new).

Include the affected version, the steps to reproduce and the impact you observed. You will get an answer within 5 working days, and a fix or a mitigation plan as soon as the issue is confirmed. We credit reporters in the release notes unless you prefer otherwise.

## Scope

In scope: the WordPress plugin (`plugin/`) and the `leiho` npm package (`bridge/`), in particular:

- the public content API (`/wp-json/leiho/v1/export`, `/content`, `/llms.txt`, `/markdown`): nothing unpublished, protected or private should be readable;
- REST permission checks and the abilities offered to AI agents (MCP);
- `postMessage` origin checks and the bridge's HTML sanitizer;
- sanitization of saved values, translations included;
- storage of deployment tokens and the signature check of updates.

Out of scope: vulnerabilities in WordPress, ACF or your front-end framework themselves, and issues that require an administrator account to exploit.
