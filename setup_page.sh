# 1) Create the page and capture its ID
PAGE_ID=$(docker compose run --rm wpcli wp post create --post_type=page --post_title="DAM Market" --post_status=publish --porcelain)

# 2) Set the slug
docker compose run --rm wpcli wp post update "$PAGE_ID" --post_name=dam-market

# 3) Assign your template file (must exist in the child theme)
docker compose run --rm wpcli wp post meta update "$PAGE_ID" _wp_page_template page-dam-market.php

# 4) Flush permalinks
docker compose run --rm wpcli wp rewrite flush --hard
