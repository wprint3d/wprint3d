# Hello World Plugin

Reference plugin for the WPrint 3D plugin platform.

## Why this example uses declarative UI

Declarative host-rendered UI is the default because it is the lightest option for constrained devices such as Raspberry Pi 3 class hardware.

## Package it

```bash
php artisan plugin:pack examples/plugins/hello-world --output examples/plugins/hello-world.w3dp
```

## Install it

```bash
php artisan plugin:install examples/plugins/hello-world.w3dp
php artisan plugin:enable wprint3d.hello-world
```
