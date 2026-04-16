# Release Checklist — arjundhi/magento2-payment

## Pre-release
- [ ] All PHP files pass `php -l` (no syntax errors)
- [ ] `composer validate --no-check-lock --strict` passes
- [ ] All XML files are well-formed (Python ET parse)
- [ ] Module name in `etc/module.xml` matches `registration.php`
- [ ] PSR-4 autoload prefix matches namespace in all PHP classes
- [ ] `CHANGELOG.md` updated with version and date
- [ ] `README.md` reflects current feature set and requirements

## Release
- [ ] Tag release: `git tag v1.0.0 && git push --tags`
- [ ] Confirm Packagist picks up new tag

## Post-release
- [ ] Run `bin/magento setup:upgrade` on a test environment
- [ ] Verify `Rameera_Payment` appears in `bin/magento module:status`
- [ ] Test payment webhook API endpoint
- [ ] Test payment retry GraphQL query
- [ ] Verify cron jobs register and execute
