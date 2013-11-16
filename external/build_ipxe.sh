#!/bin/bash

# Call: $0 <ip_file> <server_ip> <logfile>
# Self-Call: $0 --exec <ip_file> <server_ip>

if [ $# -lt 3 ]; then
	echo "Falscher Aufruf: Keine zwei Parameter angegeben!"
	exit 1
fi

if [ "$1" != "--exec" ]; then
	$0 --exec "$1" "$2" > "$3" 2>&1 &
	RET=$!
	echo "PID: ${RET}."
	exit 0
fi

FILE="$2"
SERVER="$3"

cd "/opt/openslx/ipxe/src"

[ -e "bin/undionly.kkkpxe" ] && unlink "bin/undionly.kkkpxe"

make bin/undionly.kkkpxe EMBED=../ipxelinux.ipxe,../pxelinux.0

if [ ! -e "bin/undionly.kkkpxe" -o "$(stat -c %s "bin/undionly.kkkpxe")" -lt 80000 ]; then
	echo "Error compiling ipxelinux.0"
	exit 1
fi

if ! cp "bin/undionly.kkkpxe" "/srv/openslx/tftp/ipxelinux.0"; then
	echo "** Error copying ipxelinux.0 to target **"
	exit 1
fi

echo -n "$SERVER" > "$FILE"
echo " ** SUCCESS **"
exit 0

