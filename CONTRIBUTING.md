# Contributing to INB PHP Framework

Ideas, bug reports and code are all welcome.

## Branches

| Branch | Purpose | Who can change it |
|---|---|---|
| `community` | Where all improvements happen. Start here. | Changes arrive through reviewed pull requests |
| `main` | The stable release line | Locked. Nobody can push to it |

## Share an idea or ask a question

Use the **Discussions** tab. For a bug, open an **Issue** with the steps to reproduce it, what you expected and what happened.

**Found a security problem?** Don't post it publicly. Use **Security → Report a vulnerability** so it can be fixed before it is announced.

## Send a code change

1. Fork the repository and create a branch from `community`.
2. Set up the project:

   ```bash
   cp .env.example .env
   php console key:generate
   php console migrate
   ```

3. Make your change and add tests for it.
4. Check your work:

   ```bash
   php console lint
   php console test
   ```

5. Open a pull request **into `community`** and describe what changed and why.

The automated checks run on every pull request and must pass before it can be merged.

## Guidelines

- PHP 8.2 or newer, with PSR-12 formatting.
- Keep each pull request to one change.
- Update the pages in `resources/docs` when behaviour changes.
- Never commit `.env`, API keys, passwords, database dumps or customer data.
