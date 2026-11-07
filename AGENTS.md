# Repository Guidelines

## Backend Framework & Architecture

Use the existing LavaLust backend framework for all backend development. Follow its MVC architecture: controllers coordinate requests, models handle persistence, and views render output. Register routes in `app/config/routes.php`. Reuse existing framework methods and project utilities before adding implementations; for example, load views through `$this->call->view(...)`, as `Welcome` does. Inspect relevant APIs before using them. Do not introduce alternative backend frameworks or unnecessary dependencies.

Create and alter database tables through the existing LavaLust migration feature in `app/migrations/`. Preserve applied migrations; add subsequent schema changes in new migrations using the installed DBForge methods where supported.

Reuse the database adapter registered in the controller's `properties['db']`. Only invoke the database loader when that entry is absent: the installed loader can create a new connection on repeated calls, which would split a multi-model transaction.

## Project Structure & Module Organization

This repository contains the CFABS backend built on LavaLust's PHP MVC framework. Application code belongs in `app/`: controllers handle requests, models handle persistence, and views render templates. Define routes in `app/config/routes.php`; keep other configuration in `app/config/`. Use `app/helpers/`, `app/libraries/`, and `app/middlewares/` for reusable application behavior. Database migrations live in `app/migrations/`, using numbered filenames such as `001_create_users_table.php`.

`public/index.php` is the web entry point; place public assets under `public/`. `console/cli.php` implements the `lava` command. `scheme/` contains framework internals; prefer application extensions over core changes. `runtime/` holds generated data and must be writable. Dependency-free checks and opt-in HTTP/database integration tests live in `tests/`; see `docs/development.md` for setup.

## Build, Test, and Development Commands

Use PHP 8.2 or newer. Run these commands from the repository root (`Lavalust/` within the parent workspace):

- `Copy-Item .env.example .env` — initialize local configuration in PowerShell; preserve any existing `.env`.
- `php lava` — list supported CLI commands.
- `composer install` — install locked backend dependencies for token verification and report generation.
- `php lava serve --port=3000` — start the development server at `http://127.0.0.1:3000`.
- `php lava route:list` — inspect registered routes.
- `php lava make:controller Booking` — generate a controller following framework conventions.
- `php -l app/controllers/Welcome.php` — check a PHP file for syntax errors; repeat for changed PHP files.

## Coding Style & Naming Conventions

Match the surrounding file's formatting: existing application PHP uses tabs and same-line braces, while CLI code uses four spaces. Match class and filename capitalization, for example `Welcome.php` and `class Welcome`. Use descriptive snake_case view and helper filenames, such as `welcome_page.php`. Preserve direct-access guards in application PHP files. No PHP formatter is configured.

## Testing Guidelines

Use the existing PHP scripts in `tests/`; no external testing framework or coverage threshold is configured. Before submitting, lint changed PHP files, start the server, and exercise affected routes, including invalid input and error paths. Document reproduction steps and observed results in the pull request.

## Commit & Pull Request Guidelines

History uses free-form messages and version-focused fixes; Conventional Commits are not established. Use specific, imperative subjects and focused branches. Pull requests should explain behavior changes, link relevant issues, list validation performed, and include screenshots for visual changes. Describe configuration or migration requirements explicitly.

## Scope & Efficient Workflow

Make the smallest change needed for the task. Avoid unnecessary rewrites, unrelated refactoring, and formatting churn. Inspect only relevant files and directly related dependencies; expand the scope only when required to understand or verify the change. Reuse information already read, batch related searches, and minimize repeated reads, unnecessary tool calls, and context consumption. Keep responses concise: report important changes, validation results, and unresolved limitations.

## Security & Configuration

Keep credentials and generated keys in the ignored `.env`; commit only placeholder configuration. Never commit runtime logs, caches, or sensitive request data. Review database settings and the base URL before running locally.

## Separate Frontend Project

The React frontend lives in `../frontend/`, beside this LavaLust repository at `Booking-System/frontend/`. Its contributor instructions and UI/UX rules are in `../frontend/AGENTS.md`; its setup commands are in `../frontend/README.md`. Keep backend work here and frontend work there. The projects will be deployed separately. Do not recreate a frontend directory inside this repository.
