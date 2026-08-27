# https://docs.docker.com/compose/reference/
# sudo docker compose --file docker-compose.yml up -d
version: '3.1'

services:

  db:
    image: postgres:15
    volumes:
      # ../files/ survives Dokploy redeploys; ./ (inside code/) does NOT.
      - "../files/plan/db:/var/lib/postgresql/data"
    environment:
      POSTGRES_USER: rosario
      POSTGRES_PASSWORD: rosariopwd
      POSTGRES_DB: rosariosis

  web:
    # Build from the local Dockerfile so the gd and zip extension
    # installs actually run. Requires Compose Type = "Docker Compose".
    build: .
    #image: rosariosis/rosariosis:master
    ports:
      # host:container
      - "8001:80"
    # Warning: if you use a different port (ie. 8080) for host & access from http://localhost:8080
    # wkhtmltopdf will fail with network error: ConnectionRefusedError
    # To fix that, you can use this hack
    # https://stackoverflow.com/questions/24319662/from-inside-of-a-docker-container-how-do-i-connect-to-the-localhost-of-the-mach#answer-38753971
    #extra_hosts:
      # Add `127.0.0.1 rosariosisdocker.local` to your /etc/hosts file
      # Then, access from http://rosariosisdocker.local:8080
      # - "rosariosisdocker.local:$DOCKERHOST"
    depends_on:
      - db
    volumes:
      - "../files/plan/rosariosis:/var/www/html"
    environment:
      PGHOST: db
      PGUSER: rosario
      PGPASSWORD: rosariopwd
      PGDATABASE: rosariosis
      ROSARIOSIS_YEAR: 2026
#      ROSARIOSIS_LANG: 'es_ES'
