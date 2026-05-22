# MikroTik API Monitor

MikroTik API Monitor is a web-based monitoring dashboard for MikroTik RouterOS devices. The project is designed to poll RouterOS data and display useful router, interface, DHCP, and system information in a browser-friendly format.

> **Status:** This project is still in active development. Features, file structure, UI layout, and configuration options may change while the project is being built and tested.

## Purpose

The goal of this project is to provide a lightweight custom dashboard for monitoring a MikroTik router without relying only on the built-in RouterOS interface graphs or WinBox views.

It is intended to make common router status information easier to view at a glance, especially for systems with many Ethernet, SFP, SFP+, and high-speed interfaces.

## Main Functions

### Router status monitoring

The dashboard is intended to display key RouterOS status information such as:

- CPU usage
- Memory usage
- Storage usage
- Temperature values where available
- Sector writes and storage health information where available
- Bad block information where available
- Uptime and general device status information

### Interface monitoring

The monitor can show RouterOS interface information, including:

- Interface names
- Link status
- Running/enabled state
- RX and TX traffic rates
- Interface speed where reported
- Interface temperatures where reported
- Graph/sparkline style traffic history
- Optional hiding or filtering of interfaces that do not report certain values

This is useful for routers with multiple LAN, WAN, SFP, SFP+, or 25G interfaces where the standard RouterOS display can become difficult to scan quickly.

### DHCP lease display

The project includes support for viewing DHCP lease information, such as:

- Client address
- MAC address
- Hostname/client name where available
- Lease status
- Associated bridge/interface information where available

This helps identify what devices are currently connected to the LAN and where they are connected.

### RouterOS REST/API polling

The dashboard is built around polling RouterOS data from API/REST endpoints and refreshing the web interface without requiring a full page reload.

The intended behaviour is to periodically fetch fresh JSON data and update the displayed values automatically.

### Graphing and visual display

The project includes browser-side visual display features such as:

- Live/refreshing interface graphs
- Auto-scaling graph values
- Usage indicators for CPU, memory, storage, and interface traffic
- Colour-coded values for easier reading
- Compact table views for large interface lists

The visual layout is being adjusted as the project evolves.

## Typical Use Case

This project is aimed at users who want a dedicated local dashboard for a MikroTik router, for example:

- Monitoring a main home or lab router
- Watching high-speed WAN/LAN traffic
- Checking SFP/SFP+ port activity
- Tracking CPU, memory, and storage use
- Viewing DHCP leases from a browser
- Building a custom status page for a RouterOS network

## Configuration Notes

Exact configuration may change during development, but the general setup is expected to involve:

1. Enabling the required RouterOS API or REST access.
2. Creating or using a RouterOS user with suitable read-only permissions where possible.
3. Restricting RouterOS API/REST access to trusted LAN addresses only.
4. Configuring the dashboard with the router address and credentials.
5. Hosting the PHP/JavaScript files on a local web server.
6. Opening the dashboard in a browser to view live router data.

## Security Notes

Do not expose this dashboard directly to the public internet.

Recommended precautions:

- Keep the dashboard LAN-only or VPN-only.
- Use a restricted RouterOS account where possible.
- Do not commit passwords, API tokens, `.env` files, or local configuration files to GitHub.
- Restrict RouterOS API/REST services by source IP address.
- Use HTTPS where practical.

## Suggested `.gitignore` Items

Depending on how the local configuration is stored, files like these should normally be excluded from Git:

```gitignore
.env
.env.*
config.php
config.local.php
*.log
/cache/
/tmp/
/backups/
```

## Development Status

This repository is currently a work in progress.

Current focus areas include:

- Improving dashboard layout
- Refining table and graph displays
- Improving interface filtering
- Making CPU, memory, storage, and temperature values easier to read
- Improving RouterOS API/REST data handling
- Cleaning up configuration and deployment steps
- Documenting the setup process more fully

## Project Notes

The dashboard is being developed and tested against a MikroTik RouterOS environment with multiple physical interfaces and high-speed links. Some displayed values depend on what RouterOS reports for the specific router model, RouterOS version, and installed modules.

Not every router or interface will report every field, so the dashboard should be expected to handle missing values gracefully.

## License

No license has been specified yet. Add a license before publishing or accepting external contributions.
