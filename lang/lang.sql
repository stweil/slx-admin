ALTER TABLE cat_setting ADD COLUMN en varchar(250);
ALTER TABLE cat_setting ADD COLUMN de varchar(250);
ALTER TABLE cat_setting ADD COLUMN pt varchar(250);

UPDATE cat_setting SET en='Uncategorized', de='Unkategorisiert', pt='Sem Categoria' WHERE catid=0;
UPDATE cat_setting SET en='Inactivity and Shutdown', de='Inaktivität und Abschaltung', pt='Inatividade e Desligamento' WHERE catid=1;
UPDATE cat_setting SET en='Internet Access', de='Internetzugriff', pt='Acesso à Internet' WHERE catid=2;
UPDATE cat_setting SET en='Time Synchronization', de='Zeitsynchronisation', pt='Sincronização de Tempo' WHERE catid=3;
UPDATE cat_setting SET en='Basic System', de='Grundsystem', pt='Sistema Básico' WHERE catid=4;

ALTER TABLE setting ADD COLUMN en text;
ALTER TABLE setting ADD COLUMN de text;
ALTER TABLE setting ADD COLUMN pt text;

UPDATE setting SET en='To load addons. There currently only vmware is available.', de='Zu ladende Addons. Zur Zeit steht nur vmware zur Verfügung.', pt='Para carregar addons. Atualmente apenas o vmware está disponível.' WHERE setting='SLX_ADDONS';

UPDATE setting SET en='Determines whether logins and logouts of the users should be reported to the satellite.
*yes* = login with user ID
*anonymous* = anonymous login
*no* = no login', de='Legt fest, ob Logins und Logouts der Benutzer an den Satelliten gemeldet werden sollen.
*yes* = Mit Benutzerkennung loggen
*anonymous* = Anonym loggen
*no* = Nicht loggen', pt='Determina se logins e logouts dos usuários devem ser reportados ao satélite.
*yes* = Login com ID de usuário
*anonymous* = login anônimo
*no* = sem login' WHERE setting='SLX_REMOTE_LOG_SESSIONS';

UPDATE setting SET en='Time /in seconds/, in which a user session may remain without action before it is terminated.
Leave field blank to disable the function.', de='Zeit /in Sekunden/, die eine Benutzersitzung ohne Aktion sein darf, bevor sie beendet wird.
Feld leer lassen, um die Funktion zu deaktivieren.', pt='Hora /em segundos/, em que uma sessão de usuário pode permanecer sem ação antes de ser encerrada.
Deixe o campo em branco para desativar a função.' WHERE setting='SLX_LOGOUT_TIMEOUT';

UPDATE setting SET en='Fixed time to turn off the computer, even if there is a user active.
Several times can be specified, separated by spaces.', de='Feste Uhrzeit, zu der sich die Rechner ausschalten, auch wenn noch ein Benutzer aktiv ist.
Mehrere Zeitpunkte können durch Leerzeichen getrennt angegeben werden.', pt='Horário fixo para desligar o computador, até mesmo caso tenha um usuário ativo.
Múltiplos horários podem ser especificados, separados por espaços.' WHERE setting='SLX_SHUTDOWN_SCHEDULE';

UPDATE setting SET en='Time in seconds after which a computer is switched off, if no user is logged on.
Leave blank to disable the function.', de='Zeit in Sekunden, nach dem ein Rechner abgeschaltet wird, sofern kein Benutzer angemeldet ist.
Feld leer lassen, um die Funktion zu deaktivieren.', pt='Tempo em segundos no qual um computador é desligado, caso não tenha um usuário logado.
Deixar em branco para desabilitar a função.' WHERE setting='SLX_SHUTDOWN_TIMEOUT';

UPDATE setting SET en='DNS domain in which the client integrate, provided the DHCP server does not specifies such.', de='DNS-Domäne, in die sich die Clients eingliedern, sofern der DHCP Server keine solche vorgibt.', pt='Domínio DNS no qual o cliente se integra, desde que o servidor DHCP não especifique tal.' WHERE setting='SLX_NET_DOMAIN';

UPDATE setting SET en='Address or addresses ranges in which the proxy server is not used (for example the address range of the device). Valid entries are individual IP addresses and IP ranges in CIDR notation (for example 1.2.0.0/16). Multiple selections can be separated by spaces.', de='Adressen bzw. Adressbereiche, für die der Proxyserver nicht verwendet werden soll (z.B. der Adressbereich der Einrichtung). Gültige Angaben sind einzelne IP-Adressen, sowie IP-Bereiche in CIDR-Notation (z.B. 1.2.0.0/16). Mehrere Angaben können durch Leerzeichen getrennt werden.', pt='Intervalos de endereços em que o servidor proxy não é usado (por exemplo, o intervalo de endereço do dispositivo). As entradas válidas são endereços IP individuais e intervalos de IP em notação CIDR (por exemplo 1.2.0.0/16). Seleções múltiplas podem ser separadas por espaços.' WHERE setting='SLX_PROXY_BLACKLIST';

UPDATE setting SET en='The address to use for the proxy server.', de='Die Adresse des zu verwendenden Proxy Servers.', pt='O endereço de servidor proxy a ser usado.' WHERE setting='SLX_PROXY_IP';

UPDATE setting SET en='Determines whether a proxy server is required to access the Internet.
*off* = do not use a Proxy.
*on* = Always use proxy.
*auto* = Only use proxy when the client PC is in a private address space.', de='Legt fest, ob zum Zugriff aufs Internet ein Proxy-Server benötigt wird.
*off* = keinen Proxy benutzen.
*on* = Proxy immer benutzen.
*auto* = Proxy nur benutzen, wenn sich der Client-PC in einem privaten Adressbereich befindet.', pt='Determina se um servidor proxy é necessário para acessar a Internet.
*off* = não utilizar proxy.
*on* = sempre utilizar proxy.
*auto* = apenas utilizar proxy quando o PC cliente estiver em um espaço de endereço privado.' WHERE setting='SLX_PROXY_MODE';

UPDATE setting SET en='The port to use for the proxy server.', de='Der Port des zu verwendenden Proxy Servers.', pt='A porta a ser utilizada pelo servidor proxy.' WHERE setting='SLX_PROXY_PORT';

UPDATE setting SET en='Type of the proxy.
*socks4*, *socks5*,
*http-connect* (HTTP proxy with support from the CONNECT method),
*http-relay* (Classic HTTP proxy)', de='Art des Proxys.
*socks4*, *socks5*,
*http-connect* (HTTP Proxy mit Unterstützung der CONNECT-Methode),
*http-relay* (Klassischer HTTP Proxy)', pt='Tipo do proxy.
*socks4*, *socks5*,
*http-connect* (Proxy HTTP com suporte ao método CONNECT),
*http-relay* (Clássico proxy HTTP)' WHERE setting='SLX_PROXY_TYPE';

UPDATE setting SET en='Specifies whether and how the internal clock of the computer should be set in relation to the system time of the /MiniLinux/.
*off* = The internal clock of the computer is not changed.
*local* = The internal clock is set to local time. Preferably if, for example, there is still a native Windows installation available on the PC.
*utc* = The internal clock is set to the /Coordinated Universal Time/. This is the most common setup in a pure Linux environment.', de='Legt fest, ob und wie die interne Uhr des Rechners im Bezug auf die Systemzeit des /MiniLinux/ gesetzt werden soll.
*off* = Die interne Uhr des Rechners wird nicht verändert.
*local* = Die interne Uhr wird auf die Lokalzeit gesetzt. Bevorzugt wenn z.B. noch eine native Windows-Installation auf dem PC vorhanden ist.
*utc* = Die interne Uhr wird auf die /Koordinierte Weltzeit/ gesetzt. Dies ist die gängige Einstellung in einem reinen Linux-Umfeld.', pt='Especifica se e como o relógio interno do computador deve ser definido em relação ao horário do sistema do /MiniLinux/.
*off* = O relógio interno do computador não é alterado.
*local* = O relógio interno está definido para a hora local. De preferência se, por exemplo, ainda existe uma instalação Windows nativo disponível no PC.
*utc* = O relógio interno é definido para o /Tempo Universal Coordenado/. Esta é a configuração mais comum em um ambiente puramente Linux' WHERE setting='SLX_BIOS_CLOCK';

UPDATE setting SET en='Address of the NTP time server. Multiple servers can be specified separated by spaces.
The servers are queried in sequence until a responding server is found.', de='Adresse des NTP-Zeitservers. Es können mehrere Server mit Leerzeichen getrennt angegeben werden.
Die Server werden der Reihe nach angefragt, bis ein antwortender Server gefunden wird.', pt='Endereço do servidor de horário NTP. Vários servidores podem ser especificados separados por espaços.
Os servidores são consultados em seqüência até que um servidor respondendo for encontrado.' WHERE setting='SLX_NTP_SERVER';

UPDATE setting SET en='The root password of the basic system. Only required for diagnostic purposes on the client.
Leave field blank to disallow root logins.
/Hint/: The password is encrypted with $6$ hash, so it is no longer readable after saving!', de='Das root-Passwort des Grundsystems. Wird nur für Diagnosezwecke am Client benötigt.
Feld leer lassen, um root-Logins zu verbieten.
/Hinweis/: Das Passwort wird crypt $6$ gehasht, daher wir das Passwort nach dem Speichern nicht mehr lesbar sein!', pt='A senha root do sistema base. Exigido somente para fins de diagnóstico no cliente.
Deixar campo em branco para não permitir login com root.
/Dica/: A senha é criptografada com hash $6$, então se torna ilegível após ser salva!' WHERE setting='SLX_ROOT_PASS';
