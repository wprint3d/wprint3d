FROM ubuntu:22.04

ARG DEVELOPER_MODE

# Install OS dependencies
RUN apt-get update && apt-get install -y --no-install-recommends curl git unzip &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

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

# Install Node.js 21.x
RUN curl -fsSL https://deb.nodesource.com/setup_21.x | bash - &&\
    apt-get install -y nodejs &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install Yarn
RUN npm install --global yarn

# Install PNPM
RUN npm install --global pnpm

# Install JQ and file
RUN apt-get update && apt-get install -y --no-install-recommends jq file &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Copy the base code
ADD . /app

WORKDIR /app

# Install dependencies and build the frontend bundle if not in developer mode
RUN pnpm install --force --loglevel verbose &&\
    if [ "$DEVELOPER_MODE" != "true" ]; then\
        echo "Building the frontend bundle...";\
        pnpm exec expo export -p web &&\
        echo "Removing unnecessary files...";\
        pnpm store prune &&\
        rm -rf .git ~/.local/share/pnpm ~/.npm /tmp/metro-cache /usr/local/share/.cache node_modules;\
    fi

# Install lighttpd
RUN apt-get update && apt-get install -y --no-install-recommends lighttpd &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Copy the lighttpd configuration
ADD internal/lighttpd.conf /etc/lighttpd/lighttpd.conf

ENTRYPOINT [ "./entrypoint_prd.sh" ]
