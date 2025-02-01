FROM ubuntu:22.04

# Install OpenJDK 17 JDK
# RUN apt-get update && apt-get install -y --no-install-recommends openjdk-17-jdk &&\
#     apt-get clean &&\
#     rm -rf /var/lib/apt/lists/*

# Install the Android SDK command line tools
# RUN curl -o /tmp/android-commandlinetools-linux.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip &&\
#     cd /tmp &&\
#     unzip /tmp/android-commandlinetools-linux.zip &&\
#     mkdir -p /tmp/android/sdk/cmdline-tools &&\
#     mv -v cmdline-tools /tmp/android/sdk/cmdline-tools/latest &&\
#     chmod +x /tmp/android/sdk/cmdline-tools/latest/bin/*

# Refresh SSL certificates
RUN rm -f /etc/ssl/certs/ca-bundle.crt &&\
    apt-get update && apt-get reinstall -y --no-install-recommends ca-certificates &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    update-ca-certificates

# Copy the project files
ADD . /app

# Set the working directory
WORKDIR /app

# Build the bundle
RUN apt-get update && apt-get install -y --no-install-recommends curl           &&\
    echo "Installing Node.js 21.x..."                                           &&\
    curl -fsSL https://deb.nodesource.com/setup_21.x | bash -                   &&\
    apt-get install -y nodejs                                                   &&\
    apt-get install -y --no-install-recommends jq file                          &&\
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
    apt-get install -y --no-install-recommends lighttpd                         &&\
    apt-get remove --purge -y curl jq file nodejs && apt-get autoremove -y      &&\
    apt-get clean                                                               &&\
    rm -rf /var/lib/apt/lists/* /tmp/metro-*

# Copy the lighttpd configuration
ADD internal/lighttpd.conf /etc/lighttpd/lighttpd.conf

ENTRYPOINT [ "./entrypoint_prd.sh" ]
