# syntax = edrevo/dockerfile-plus

INCLUDE+ ./Dockerfile.dev

# Copy the source code
ADD . /var/www

# Store the revision hash
RUN apt-get update && apt-get install -y git &&\
    git rev-parse --short HEAD > /var/www/internal/app_ver &&\
    apt-get remove -y git &&\
    apt-get autoremove -y &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /var/www

# Install dependencies
RUN composer install --no-scripts

ENTRYPOINT [ "/var/www/internal/run.sh" ]