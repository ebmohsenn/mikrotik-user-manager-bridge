# RouterOS API over TLS (8729)

## Overview
- Enables API-SSL on MikroTik RouterOS (port 8729)
- Creates a local CA and a server certificate for your router's hostname
- Restricts firewall access to your WordPress host and optional admin IPs
- Updates WordPress plugin settings (use TLS)

## Placeholders used below
Replace these with your own values:
- `router.example.com` - the DNS name WordPress uses to reach the router
- `203.0.113.10` - the public IP address of your WordPress server
- `<ADMIN_PUBLIC_IP>` - an optional static IP you administer the router from

## RouterOS Commands
1) Create and sign a local CA and server cert
```
/certificate add name=api-ca common-name=api-ca key-usage=key-cert-sign,crl-sign
/certificate sign api-ca

/certificate add name=api-cert common-name=router.example.com subject-alt-name=DNS:router.example.com
/certificate sign api-cert ca=api-ca
```

2) Enable API-SSL on port 8729 and attach certificate
```
/ip service set api-ssl disabled=no certificate=api-cert port=8729
```

3) (Optional) Disable plain API on 8728
```
/ip service set api disabled=yes
```

4) Firewall: allow only required sources, then drop others
Note: ensure these rules are placed above general drop rules in the input chain.
```
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8729 src-address=203.0.113.10 comment="Allow WordPress server"
/ip firewall filter add chain=input action=accept protocol=tcp dst-port=8729 src-address=<ADMIN_PUBLIC_IP> comment="Allow admin IP"
/ip firewall filter add chain=input action=drop   protocol=tcp dst-port=8729 comment="Drop other API-SSL"
```

## WordPress Plugin Settings
- Go to the plugin's Settings tab in wp-admin
- Check: Use TLS (port 8729)
- Port: set to 8729 (or leave empty to auto-select 8729 when TLS is enabled)
- Save and click “Test Connection”

## Notes
- Self-signed certificates are acceptable for API-SSL, but the chain will not validate: either import your CA into the WordPress server's trust store or turn off "Verify SSL" in the plugin settings (less secure). For public trust, install a proper server certificate and set it in `/ip service set api-ssl certificate=<name>`.
- If you already have a CA/cert, reuse them instead of creating new ones.
- Replace `<ADMIN_PUBLIC_IP>` above with your static admin IP, or add additional allow rules for each admin location.

