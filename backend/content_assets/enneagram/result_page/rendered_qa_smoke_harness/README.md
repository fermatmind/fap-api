# Enneagram Rendered QA + Smoke Harness

This directory defines the Enneagram result page rendered QA, API smoke, and rollback simulation evidence-bundle contract.

The harness prepares deterministic commands and validates returned evidence reports. It is safe for auto-to-staging and auto-to-report workflows.

It does not run production activation, production rollback, production writes, runtime switching, bulk content generation, or frontend changes.
