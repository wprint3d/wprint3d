<h1 style="text-align: center;"> WPrint 3D </h1>

WPrint 3D is a FDM printer web-based remote control software compatible with most common standard serial/TTY over USB printers.

Compatibility chart | Marlin 1.x | Marlin 2.x | Other firmwares
------------------- | ---------- | ---------- | ---------------
Basic functionality | ✓          | ✓          | No support
Pause states        | ✓\*        | ✓\*        | No support

\* **When paused from the web or from the G-code itself, M0, M1 and M600 color swaps are supported, however, these commands could time out on some printers, if that happens, resuming from the web won't work and you'll be required to click the physical printer's button. For compatibility and reliability reasons, M600 commands are converted to a sequence of M0 instead (to allow for resuming through the web).**

## System requirements
- Any **dual-core CPU** running at, at least, **1 GHz**
- **1 GB** of memory running at **any speed** \*
- **512 MB** of **storage space**
- A wired network card running at, at least, **10/100 Mbps mode** or a wireless network card connected to a device that can provide a **54 Mbps mode** (or better) for a stable live webcam feed
- Optionally, a USB camera or a real hardwired sensor, such as a **Raspberry Pi camera**.

\* On a Raspberry Pi 3 or any other low-memory SBC, try running a headless OS (such as **Ubuntu Server**) and strip out any unnecessary components, such as `snapd` and `multipathd`. If needed, **add a swap partition and enable swapping**. In order to enable the memory limiting features of Docker, add `cgroup_enable=memory cgroup_memory=1 swapaccount=1` at the end of the `/boot/cmdline.txt` file.

## Getting started
- [Install docker](https://docs.docker.com/desktop/install/linux-install/) as explained in the linked guide.
- [Give yourself permission to run Docker commands](https://docs.docker.com/engine/install/linux-postinstall/) by following the guide linked here, this is extremely important because we'll need to set up a few [privileged containers](https://docs.docker.com/engine/reference/commandline/run/#-full-container-capabilities---privileged).
- Clone this repository wherever you want, just make sure you'd have write permission with the user you're currently logged in.

    `git clone -b alpha https://github.com/wprint3d/wprint3d`
- Change to the created directory by running `cd wprint3d`.
- Now, using your favorite text editor, create a new file called `.env` and copy the contents of `.env.example` into it. If you're planning on running **WPrint 3D** on a **Raspberry Pi**, consider copying `.env.rpi` instead.
- That's it! Plug your printer in any USB port you like and turn it on! Now, run `bash run.sh` to get going. The first run might take a few minutes, so you'll probably want to find something else to do in the meantime.
- Once it's done, type `ifconfig` and copy the IP address of your machine. Type that IP address into the address bar of your browser, i.e.: https://192.168.0.2
- Follow the on-screen instructions.

<!-- 
## Contributing

Thank you for considering contributing to WPrint 3D! The contribution guide can be found in the [WPrint 3D documentation](#).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](#).

## Security Vulnerabilities

If you discover a security vulnerability within WPrint 3D, please send an e-mail to ... via [example@example.com](mailto:example@example.com). All security vulnerabilities will be promptly addressed. -->

## License

WPrint 3D is open-sourced software licensed under the [MIT license](LICENSE).
