# Contributing to EasySQL WordPress Plugin

Thank you for your interest in contributing to the **EasySQL WordPress Plugin**! We welcome contributions to make the plugin more powerful, reliable, and user-friendly.

This document outlines the guidelines for reporting issues, proposing features, and submitting pull requests.

---

## Code of Conduct

We are committed to providing a welcoming, inclusive, and harassment-free environment. Please be respectful, constructive, and considerate when interacting with fellow contributors and maintainers.

---

## How to Contribute

### 1. Reporting Issues

If you find a bug:
1. Search the [Issues tracker](https://github.com/Clearsoft-net/easysql-wp/issues) to ensure the issue hasn't been reported.
2. If not reported, open a new issue detailing:
   - A descriptive title.
   - Steps to reproduce.
   - Expected behavior vs. actual behavior.
   - Environment info (WordPress version, PHP version, MySQL/MariaDB version, browser).
   - Screenshots or error logs (if applicable).

### 2. Requesting Features

Ideas and feature suggestions are welcome!
- Open a feature request issue describing the use case and how it improves the WordPress admin experience.

---

## Development Setup

### Prerequisites

- [PHP](https://www.php.net/) (version 8.2 or newer)
- [Composer](https://getcomposer.org/) (version 2.x)
- [Node.js](https://nodejs.org/) & [npm](https://www.npmjs.com/) (for `@wordpress/env` if using Docker-based dev environment)
- Git

### Setup

1. **Fork the repository** on GitHub: [Clearsoft-net/easysql-wp](https://github.com/Clearsoft-net/easysql-wp).
2. **Clone your fork**:
   ```bash
   git clone https://github.com/<your-username>/easysql-wp.git
   cd easysql-wp
   ```
3. **Install Composer dependencies**:
   ```bash
   composer install
   ```

### Local Testing & Quality Assurance

| Command | Description |
|---|---|
| `composer test` | Run PHPUnit test suite |
| `composer lint` | Check PHP code style against WordPress Coding Standards (WPCS) |
| `composer lint:fix` | Automatically fix coding style violations with PHPCBF |

Ensure all tests pass before opening a Pull Request:
```bash
composer test
composer lint
```

---

## Coding Standards

- Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- PSR-4 autoloading is mapped to `EasySQL\` under `src/`.
- Ensure all input is sanitized, validated, and all output is properly escaped.
- Verify nonces and capabilities on all AJAX and REST API requests.

---

## Pull Request Process

1. Create a descriptive feature branch:
   ```bash
   git checkout -b feat/my-improvement
   ```
2. Implement your changes and add PHPUnit tests where applicable.
3. Run `composer test` and `composer lint` to verify code quality.
4. Commit your changes with clear, concise commit messages.
5. Push to your fork:
   ```bash
   git push origin feat/my-improvement
   ```
6. Open a **Pull Request** targeting the `main` branch of `Clearsoft-net/easysql-wp`.
7. Fill out the PR template explaining the changes made and referencing any related issues.

---

## License

By contributing to EasySQL WordPress Plugin, you agree that your contributions will be licensed under the project's [MIT License](./LICENSE).
