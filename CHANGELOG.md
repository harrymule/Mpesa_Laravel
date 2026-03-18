# Changelog

All notable changes to this package will be documented in this file.

## [Unreleased]

### Added
- root `.env.example` with the full `MPESA_*` configuration surface
- root `.gitignore` excluding `resources/`, `vendor/`, and local development artifacts
- detailed package documentation in `DOCUMENTATION.md`, including an architecture diagram
- configurable C2B validation responder contract and default implementation for accepting or rejecting validation callbacks
- structured logging for OAuth token fetch/cache behavior, Daraja API requests, responses, failures, and callback forwarding
- `CallbackForwardingFailed` event for reacting to failed callback delivery attempts
- dedicated queue connection support for M-Pesa callback forwarding jobs
- async batch request support using Laravel HTTP pools for batch M-Pesa operations
- unit test coverage for `Support\Mpesa`, `MpesaClient`, callback payload transformers, and callback forwarding jobs

### Changed
- tightened callback idempotency for STK, C2B confirmation, and C2B validation processing to avoid duplicate records and duplicate forwarding
- updated callback job dispatching to honor both queue connection and queue name configuration
- expanded transaction services to support batch persistence flows alongside single-request operations
- refined README setup and usage guidance to document security, validation hooks, and package configuration more clearly
- extended PHPUnit configuration to run both unit and feature suites

## [0.1.0] - 2026-03-09

### Added
- Laravel package scaffold for Safaricom Daraja integrations
- REST endpoints for STK push, C2B register/simulate, B2C, B2B, reversal, account balance, and transaction status
- callback endpoints and processors for STK, C2B, B2C, B2B, reversal, account balance, transaction status, and shared timeout handling
- persistence for STK requests, payment confirmations, and generic transaction logs
- configurable security middleware for initiation routes and callback routes
- customizable model and callback payload transformer hooks
- package test harness with PHPUnit and Testbench
- GitHub Actions workflow for automated tests

### Changed
- normalized package JSON error responses for validation, Daraja request failures, and connection failures
- split initiation and callback route groups so they can be protected independently
- expanded package event coverage to non-STK callbacks
