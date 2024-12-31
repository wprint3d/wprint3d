<div align="center">
    <h1 style="text-align: center;"> WPrint 3D </h1>
    <p align="center">
        Web-based remote control software for FDM printers via USB serial/TTY.
    </p>
</div>

> [!TIP]
> A renewed, faster and more reliable frontend is coming!

## Compatibility information

### Printer/CNC firmware

Firmware            | Support     
------------------- | -----------
Marlin 1.x          | :white_check_mark:
Marlin 2.x          | :white_check_mark:
Other firmwares     | :x: No support

> [!NOTE]  
> When paused from the web or from the G-code itself, **M0**, **M1** and **M600** color swaps are supported, however, these commands could time out on some printers, if that happens, resuming from the web won't work and you'll be required to **click the physical printer's button** *(or tap the screen if it has a touchscreen)*.
>
> For compatibility and reliability reasons, **M600** is converted to a sequence of **M0** instead (to allow for resuming through the web).

### Host architecture

Architecture        | Support
------------------- | -----------
arm                 | :exclamation: Broken (*read more below*)
arm64               | :white_check_mark:
amd64               | :white_check_mark:
x86                 | :white_check_mark:
Other architectures | :x: No support

> [!CAUTION]
> **armhf/armv7l** builds are not currently runnable, since the latest version of **MongoDB** is not compatible with the architecture.
>
> We'll migrate to **FerretDB** instead once the missing aggregation feature set is implemented.

> [!WARNING]
> Further testing is pending on small and low-powered SBCs as they may freeze sometimes.
>
> On a **Raspberry Pi 3** or any other low-memory SBC, try running a headless OS (such as **Ubuntu Server**) and strip out any unnecessary components, such as `snapd` and `multipathd`. If needed, **add a swap partition and enable swapping**.

### Operating system

Operating system    | Support
------------------- | -----------
GNU/Linux           | :white_check_mark:
Windows             | :white_check_mark:\* (experimental)
MacOS               | :grey_question: Untested
Other OSes          | :grey_question: Untested (may work if they can run Docker)

> [!NOTE]
> Windows support is experimental and relies on **WSL2** which requires **Windows 10 or greater**. If this is of your interest, read more below.

> [!IMPORTANT]
> **MacOS** users, please [create a new discussion](https://github.com/orgs/wprint3d/discussions/new?category=general) if you try to run the software!
>
> Talk about your experience and any roadblocks you've found.

## System requirements
- Any **dual-core CPU** running at, at least, **1 GHz**
- **1 GB** of memory running at **any speed** \*
- **2 GB** of **storage space**
- A wired network card running at, at least, **10/100 Mbps mode** or a wireless network card connected to a device that can provide a **54 Mbps mode** (or better) for a stable live webcam feed
- Optionally, a USB camera or a real hardwired sensor, such as a **Raspberry Pi camera**.

## Getting started
- **If you're running Windows, go to the "[Preparing your Windows host](https://github.com/wprint3d/wprint3d?tab=readme-ov-file#preparing-your-windows-host)" section first.**
- [Install docker](https://docs.docker.com/desktop/install/linux-install/) as explained in the linked guide.
- [Give yourself permission to run Docker commands](https://docs.docker.com/engine/install/linux-postinstall/) by following the guide linked here, this is extremely important because we'll need to set up a few [privileged containers](https://docs.docker.com/engine/reference/commandline/run/#-full-container-capabilities---privileged).
- Clone this repository wherever you want, just make sure you'd have write permission with the user you're currently logged in.

    `git clone -b alpha https://github.com/wprint3d/wprint3d`
- Change to the created directory by running `cd wprint3d`.
- Now, using your favorite text editor, create a new file called `.env` and copy the contents of `.env.example` into it. If you're planning on running **WPrint 3D** on a **Raspberry Pi**, consider copying `.env.rpi` instead as it's been specifically optimized to run faster on its hardware.
- That's it! Plug your printer in any USB port you like and turn it on! Now, run `bash run.sh` to get going. The first run might take a few minutes, so you'll probably want to find something else to do in the meantime.
- **If you're running Windows, go to the "[Next steps on your Windows host](https://github.com/wprint3d/wprint3d?tab=readme-ov-file#next-steps-on-your-windows-host)" section.**
- Once it's done, type `ifconfig` and copy the IP address of your machine. Type that IP address into the address bar of your browser, i.e.: https://192.168.0.2
- Follow the on-screen instructions.

## Preparing your Windows host

- [Install WSL2 and a GNU/Linux distribution of your choice](https://learn.microsoft.com/windows/wsl/install) as explained in the linked article. I highly suggest going with **Ubuntu** as it requires way less manual work in order to get started. This guide has been tested while using said distribution.
- Open a new **Terminal** and get into your GNU/Linux distribution. If your distribution is named "**Ubuntu-24.04**", type `wsl -d Ubuntu-22.04`. If you're unsure, get a list of installed distributions by running `wsl --list`.
- Go back to the "[Getting started](https://github.com/wprint3d/wprint3d?tab=readme-ov-file#getting-started)" section and follow the remaining steps to install Docker and setup your system.

## Next steps on your Windows host

> [!CAUTION]
> Unfortunately, direct binding to USB ports has been broken for a long time in **WSL2**, so you'll have to re-run these steps after each connection of your printer.
>
> The instructions will be updated whenever this gets fixed.

- From a new **Terminal** window or tab, install **usbipd-win** by running `winget install usbipd`. This is required to share USB devices between **Windows** and the **virtual machine**.

    **Do not enter your GNU/Linux distribution yet, this step MUST be done from the host!**
- Type `usbipd --list` to get a list of connected devices. Take note of the **VID:PID** field related to your printer. Mine looks like this:

        > usbipd list
        Connected:
        BUSID  VID:PID    DEVICE                                                        STATE
        ...
        1-11   2341:0042  USB Serial Device (COM4)                                      Not shared
- Enable binding by running `usbipd bind -i 2341:0042`.
- Attach the printer to **WSL2** by running `usbipd attach --wsl  2341:0042`.
- **Now**, get back into your GNU/Linux distribution. If your distribution is named "**Ubuntu-24.04**", type `wsl -d Ubuntu-22.04`. If you're unsure, get a list of installed distributions by running `wsl --list`.
- Run `hostname -I`, if more than one IP address is returned, copy the **first IP address** from the list. Then, paste it into a new browser tab.
- That's about it!

## Known bugs
- SD printing may disrupt the negotiated serial connection. The connection should come back **about a minute** after finishing the print job. **This bug will be worked on in the near future.**

<!-- 
## Contributing

Thank you for considering contributing to WPrint 3D! The contribution guide can be found in the [WPrint 3D documentation](#).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](#).

## Security Vulnerabilities

If you discover a security vulnerability within WPrint 3D, please send an e-mail to ... via [example@example.com](mailto:example@example.com). All security vulnerabilities will be promptly addressed. -->

## Documentation
Classmap-level documentation can be automatically generated by running the application in **developer mode**. To do so, run `bash run.sh -e dev`, this will activate the `documentation-generator` service and the documentation will be written to `docs/public`.

In order to view the generated web UI visit the following URL: https://\<your-LAN-ip-address\>:30000

## License

WPrint 3D is open-sourced software licensed under the [MIT license](LICENSE).
