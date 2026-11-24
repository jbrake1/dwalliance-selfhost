PayPal credentials for the store (lib/DwPayPal.php). Not used until store.json paypal.enabled is true.
Create default.json (or <company_id>.json for one company) in this folder, mode 600, owner www-data:
  {"client_id": "...", "client_secret": "...", "webhook_id": "..."}
Set store.json paypal.env to "sandbox" while testing and "live" only with live credentials.
Never commit these files anywhere.
