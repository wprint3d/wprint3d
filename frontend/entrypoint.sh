#!/bin/bash

set -e; # quit on error

echo '=> Installing Yarn dependencies...';
yarn;

echo '=> Installing packages with NPM...';
npm i --force;

npx expo start --clear;

exit $?;
