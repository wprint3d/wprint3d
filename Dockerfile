# syntax = edrevo/dockerfile-plus

INCLUDE+ ./Dockerfile.dev

# Copy the source code
ADD . /var/www

# Production images ship the plugin framework only. Example plugins remain
# available through the development stack's bind mounts.
RUN rm -rf /var/www/examples/plugins

# Set the working directory
WORKDIR /var/www

# Store the revision hash
RUN FULL_SHA=$(cat /var/www/.git/HEAD | cut -d' ' -f2)      &&\
    SHORT_SHA=$(cat /var/www/.git/${FULL_SHA} | cut -c1-7)  &&\
    printf ${SHORT_SHA} > /var/www/internal/app_ver

# Install dependencies
RUN composer install --no-scripts &&\
    composer clear-cache

ENTRYPOINT [ "/var/www/internal/run.sh" ]
