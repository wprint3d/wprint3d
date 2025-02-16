FROM alpine

# Install OS dependencies
RUN apk update && apk add --no-cache bash

# Install OpenJDK 17 JDK
# RUN apk add --no-cache openjdk17

# Install the Android SDK command line tools
# RUN wget -O /tmp/android-commandlinetools-linux.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip &&\
#     cd /tmp &&\
#     unzip /tmp/android-commandlinetools-linux.zip &&\
#     mkdir -p /tmp/android/sdk/cmdline-tools &&\
#     mv -v cmdline-tools /tmp/android/sdk/cmdline-tools/latest &&\
#     chmod +x /tmp/android/sdk/cmdline-tools/latest/bin/*

# Build the bundle
RUN --mount=type=bind,src=.,target=/source,rw \
    cd /source &&\
    cp -fv public/index.production.html public/index.html                       &&\
    apk add --no-cache curl                                                     &&\
    echo "Installing Node.js..."                                                &&\
    apk add --no-cache nodejs npm                                               &&\
    echo "Installing Yarn..."                                                   &&\
    npm install --global yarn                                                   &&\
    echo "Installing PNPM..."                                                   &&\
    npm install --global pnpm                                                   &&\
    echo "Installing dependencies..."                                           &&\
    pnpm install --force --loglevel verbose                                     &&\
    echo "Building the frontend bundle..."                                      &&\
    pnpm exec expo export -p web                                                &&\
    echo "Removing unnecessary files..."                                        &&\
    pnpm store prune                                                            &&\
    rm -rf  .git node_modules \
            ~/.local/share/pnpm ~/.npm /usr/lib/node_modules \
            /usr/bin/node /usr/bin/npm /usr/bin/pnpm /usr/bin/pnpx \
            /tmp/metro-cache /usr/local/share/.cache                            &&\
    echo "Installing HTTP server..."                                            &&\
    apk add --no-cache lighttpd                                                 &&\
    apk del curl nodejs npm                                                     &&\
    rm -rf /var/cache/apk/* /usr/local/lib/node_modules \
           /tmp/metro-*     /tmp/node-compile-cache                             &&\
    mkdir -p /app                                                               &&\
    cp -rf /source/dist /app/dist

# Add the entrypoint script
ADD entrypoint_prd.sh /app/entrypoint_prd.sh

# Set the working directory
WORKDIR /app

# Copy the lighttpd configuration
ADD internal/lighttpd.conf /etc/lighttpd/lighttpd.conf

ENTRYPOINT [ "/app/entrypoint_prd.sh" ]
