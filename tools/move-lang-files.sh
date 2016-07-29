#!/bin/bash

declare -rg PID=$$

perror() {
	echo "[ERROR] $*"
	[ "$$" != "$PID" ] && kill "$PID"
	exit 1
}

[ "$2" = "modules" -o "$2" = "templates" ] || perror "Second option must be modules or templates"

declare -rg TRANS="$1"
[ -z "$TRANS" ] && perror "Usage: $0 <language> modules|templates"
declare -rg DIR="lang/${TRANS}/$2"
[ -d "$DIR" ] || perror "No old modules dir for lang $TRANS"

for mod in $(ls -1 "$DIR"); do
	[ -d "$DIR/$mod" ] || continue
	[ -z "$(ls -1 "$DIR/$mod")" ] && continue
	DEST="modules/$mod/lang/$TRANS/templates"
	echo " ******** $DIR/$mod   -->   $DEST *********"
	mkdir -p "$DEST" || perror "Could not create $DEST"
	cp -v -a "$DIR/$mod/"* "$DEST/" || perror "Could not copy"
	git rm -r "$DIR/$mod"
	git add "$DEST"
done

echo " -- Categories --"

if [ -n "$(ls -1 "lang/${TRANS}/settings/")" ]; then
	git mv "lang/${TRANS}/settings/"*.json "modules/baseconfig/lang/${TRANS}/" || perror "Could not move settings/categories names"
fi

