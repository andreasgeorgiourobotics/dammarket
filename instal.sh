docker compose run --rm wpcli wp core install \
  --url=http://localhost:8080 \
  --title="DAM Market" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com

# prettier permalinks
docker compose run --rm wpcli wp rewrite structure '/%postname%/'
docker compose run --rm wpcli wp rewrite flush --hard
