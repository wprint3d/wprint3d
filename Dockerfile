# syntax = edrevo/dockerfile-plus

INCLUDE+ ./Dockerfile.dev

# Copy the source code
ADD . /var/www

# Set the working directory
WORKDIR /var/www

# Install dependencies
RUN composer install --no-scripts

ENTRYPOINT [ "/var/www/internal/run.sh" ]