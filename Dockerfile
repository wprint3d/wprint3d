# syntax = edrevo/dockerfile-plus

INCLUDE+ ./Dockerfile.dev

# Copy the source code
ADD . /var/www

# Store the revision hash
RUN git rev-parse --short HEAD > /var/www/internal/app_ver;

# Set the working directory
WORKDIR /var/www

# Install dependencies
RUN composer install --no-scripts

ENTRYPOINT [ "/var/www/internal/run.sh" ]