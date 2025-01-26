FROM ubuntu:22.04

# Install OS dependencies
RUN apt-get update && apt-get install -y --no-install-recommends build-essential curl git unzip rsync &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install OpenJDK 17 JDK
RUN apt-get update && apt-get install -y --no-install-recommends openjdk-17-jdk &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install the Android SDK command line tools
RUN curl -o /tmp/android-commandlinetools-linux.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip &&\
    cd /tmp &&\
    unzip /tmp/android-commandlinetools-linux.zip &&\
    mkdir -p /tmp/android/sdk/cmdline-tools &&\
    mv -v cmdline-tools /tmp/android/sdk/cmdline-tools/latest &&\
    chmod +x /tmp/android/sdk/cmdline-tools/latest/bin/*

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

# Install JQ
RUN apt-get update && apt-get install -y --no-install-recommends jq &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install file
RUN apt-get update && apt-get install -y --no-install-recommends file &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Copy the base code
ADD . /app

WORKDIR /app

# Install dependencies
RUN pnpm install --force --loglevel verbose

ENTRYPOINT [ "./entrypoint.sh" ]