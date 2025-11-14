## TODO

- [ ] **Add PHP build step:**  
  Use Composer to require the [`opis/json-schema`](https://github.com/opis/json-schema) package:
  ```sh
  composer require opis/json-schema
  ```
- [ ] **Hook up monitoring/CI:**  
  Re-enable the admin SPA/PHP QA workflows and wire the staging socket-health script (`/var/www/vhosts/twp.tech/monitoring/socket-health.sh`) into an alerting channel so CPS outages trigger actionable notifications.
