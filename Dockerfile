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

# Install dependencies and compile licenses
RUN composer install --no-scripts &&\
    composer clear-cache &&\
    apt-get update && apt-get install -y --no-install-recommends nodejs npm &&\
    npm install --global pnpm &&\
    cd /var/www/frontend &&\
    pnpm install --force --ignore-scripts --loglevel error &&\
    cd /var/www &&\
    bash /var/www/internal/refresh-third-party-licenses.sh /var/www &&\
    rm -rf /var/www/frontend/node_modules /root/.npm /root/.local/share/pnpm /usr/local/lib/node_modules &&\
    rm -f /usr/local/bin/pnpm /usr/local/bin/pnpx &&\
    apt-get purge -y nodejs npm &&\
    apt-get autoremove -y &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

ENTRYPOINT [ "/var/www/internal/run.sh" ]
