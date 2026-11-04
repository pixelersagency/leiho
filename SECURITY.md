# Security policy

## Supported versions

Security fixes go into the latest release only.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through GitHub's [private vulnerability reporting](https://github.com/pixelersagency/leiho/security/advisories/new).

Include the affected version, the steps to reproduce and the impact you observed. You will get an answer within 5 working days, and a fix or a mitigation plan as soon as the issue is confirmed. We credit reporters in the release notes unless you prefer otherwise.

## Scope

In scope: the WordPress plugin (`plugin/`) and the bridge (`bridge/`), in particular REST permission checks, `postMessage` origin checks, sanitization of saved values and storage of deployment tokens.

Out of scope: vulnerabilities in WordPress, ACF or your front-end framework themselves, and issues that require an administrator account to exploit.
