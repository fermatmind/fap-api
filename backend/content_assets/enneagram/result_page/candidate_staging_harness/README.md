# Enneagram Candidate Export + Staging Import Harness

This directory defines the automation harness contract for Enneagram result page candidate export and staging-only inactive import validation.

The harness can validate a provided candidate directory, optionally run the existing production-equivalent candidate payload exporter, and optionally run the existing inactive candidate release importer. It is staging/inactive only.

It does not run production import, production activation, runtime switching, production writes, or frontend changes.
