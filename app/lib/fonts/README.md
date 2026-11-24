DejaVu Sans 2.37, used to draw watermark text with PHP GD (refs #51).

These files live under `/var/www/html/lib/fonts` so php-fpm can read them.
The staging (and production) php-nginx pool sets `open_basedir` to
`/var/www/html:/var/lib/app/data:/dev/shm:/tmp`, which does **not** include
`/usr/share/fonts`. Installing `fonts-dejavu-core` in the container is still
useful, but the app must not depend on that path.

License: SIL Open Font License (see LICENSE).
