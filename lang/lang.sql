ALTER TABLE cat_setting ADD COLUMN english varchar(250);
ALTER TABLE cat_setting ADD COLUMN german varchar(250);
ALTER TABLE cat_setting ADD COLUMN portuguese varchar(250);

UPDATE cat_setting SET english='Uncategorized', german='Unkategorisiert', portuguese='Sem Categoria' WHERE catid=0;
UPDATE cat_setting SET english='Inactivity and Shutdown', german='Inaktivität und Abschaltung', portuguese='Inatividade e Desligamento' WHERE catid=1;
UPDATE cat_setting SET english='Internet Access', german='Internetzugriff', portuguese='Acesso à Internet' WHERE catid=2;
UPDATE cat_setting SET english='Time Synchronization', german='Zeitsynchronisation', portuguese='Sincronização de Tempo' WHERE catid=3;
UPDATE cat_setting SET english='Basic System', german='Grundsystem', portuguese='Sistema Básico' WHERE catid=4;

ALTER TABLE setting ADD COLUMN english text;
ALTER TABLE setting ADD COLUMN german text;
ALTER TABLE setting ADD COLUMN portuguese text;

UPDATE setting SET english='To load addons. There currently only vmware is available.', german='Zu ladende Addons. Zur Zeit steht nur vmware zur Verfügung.', portuguese='Para carregar addons. Atualmente apenas o vmware está disponível.' WHERE setting='SLX_ADDONS';

UPDATE setting SET english='Determines whether logins and logouts of the users should be reported to the satellite.
*yes* = login with user ID
*anonymous* = anonymous login
*no* = no login', german='Legt fest, ob Logins und Logouts der Benutzer an den Satelliten gemeldet werden sollen.
*yes* = Mit Benutzerkennung loggen
*anonymous* = Anonym loggen
*no* = Nicht loggen', portuguese='Determina se logins e logouts dos usuários devem ser reportados ao satélite.
*yes* = Login com ID de usuário
*anonymous* = login anônimo
*no* = sem login' WHERE setting='SLX_REMOTE_LOG_SESSIONS';

UPDATE setting SET english='Time /in seconds/, in which a user session may remain without action before it is terminated.
Leave field blank to disable the function.', german='Zeit /in Sekunden/, die eine Benutzersitzung ohne Aktion sein darf, bevor sie beendet wird.
Feld leer lassen, um die Funktion zu deaktivieren.', portuguese='Hora /em segundos/, em que uma sessão de usuário pode permanecer sem ação antes de ser encerrada.
Deixe o campo em branco para desativar a função.' WHERE setting='SLX_LOGOUT_TIMEOUT';

UPDATE setting SET english='Fixed time to turn off the computer, even if there is a user active.
Several times can be specified, separated by spaces.', german='Feste Uhrzeit, zu der sich die Rechner ausschalten, auch wenn noch ein Benutzer aktiv ist.
Mehrere Zeitpunkte können durch Leerzeichen getrennt angegeben werden.', portuguese='Horário fixo para desligar o computador, até mesmo caso tenha um usuário ativo.
Múltiplos horários podem ser especificados, separados por espaços.' WHERE setting='SLX_SHUTDOWN_SCHEDULE';

UPDATE setting SET english='Time in seconds after which a computer is switched off, if no user is logged on.
Leave blank to disable the function.', german='Zeit in Sekunden, nach dem ein Rechner abgeschaltet wird, sofern kein Benutzer angemeldet ist.
Feld leer lassen, um die Funktion zu deaktivieren.', portuguese='Tempo em segundos no qual um computador é desligado, caso não tenha um usuário logado.
Deixar em branco para desabilitar a função.' WHERE setting='SLX_SHUTDOWN_TIMEOUT';

UPDATE setting SET english='DNS domain in which the client integrate, provided the DHCP server does not specifies such.', german='DNS-Domäne, in die sich die Clients eingliedern, sofern der DHCP Server keine solche vorgibt.', portuguese='Domínio DNS no qual o cliente se integra, desde que o servidor DHCP não especifique tal.' WHERE setting='SLX_NET_DOMAIN';

UPDATE setting SET english='Address or addresses ranges in which the proxy server is not used (for example the address range of the device). Valid entries are individual IP addresses and IP ranges in CIDR notation (for example 1.2.0.0/16). Multiple selections can be separated by spaces.', german='Adressen bzw. Adressbereiche, für die der Proxyserver nicht verwendet werden soll (z.B. der Adressbereich der Einrichtung). Gültige Angaben sind einzelne IP-Adressen, sowie IP-Bereiche in CIDR-Notation (z.B. 1.2.0.0/16). Mehrere Angaben können durch Leerzeichen getrennt werden.', portuguese='Intervalos de endereços em que o servidor proxy não é usado (por exemplo, o intervalo de endereço do dispositivo). As entradas válidas são endereços IP individuais e intervalos de IP em notação CIDR (por exemplo 1.2.0.0/16). Seleções múltiplas podem ser separadas por espaços.' WHERE setting='SLX_PROXY_BLACKLIST';

UPDATE setting SET english='The address to use for the proxy server.', german='Die Adresse des zu verwendenden Proxy Servers.', portuguese='O endereço de servidor proxy a ser usado.' WHERE setting='SLX_PROXY_IP';

UPDATE setting SET english='Determines whether a proxy server is required to access the Internet.
*off* = do not use a Proxy.
*on* = Always use proxy.
*auto* = Only use proxy when the client PC is in a private address space.', german='Legt fest, ob zum Zugriff aufs Internet ein Proxy-Server benötigt wird.
*off* = keinen Proxy benutzen.
*on* = Proxy immer benutzen.
*auto* = Proxy nur benutzen, wenn sich der Client-PC in einem privaten Adressbereich befindet.', portuguese='Determina se um servidor proxy é necessário para acessar a Internet.
*off* = não utilizar proxy.
*on* = sempre utilizar proxy.
*auto* = apenas utilizar proxy quando o PC cliente estiver em um espaço de endereço privado.' WHERE setting='SLX_PROXY_MODE';

UPDATE setting SET english='The port to use for the proxy server.', german='Der Port des zu verwendenden Proxy Servers.', portuguese='A porta a ser utilizada pelo servidor proxy.' WHERE setting='SLX_PROXY_PORT';

UPDATE setting SET english='Type of the proxy.
*socks4*, *socks5*,
*http-connect* (HTTP proxy with support from the CONNECT method),
*http-relay* (Classic HTTP proxy)', german='Art des Proxys.
*socks4*, *socks5*,
*http-connect* (HTTP Proxy mit Unterstützung der CONNECT-Methode),
*http-relay* (Klassischer HTTP Proxy)', portuguese='Tipo do proxy.
*socks4*, *socks5*,
*http-connect* (Proxy HTTP com suporte ao método CONNECT),
*http-relay* (Clássico proxy HTTP)' WHERE setting='SLX_PROXY_TYPE';

UPDATE setting SET english='Specifies whether and how the internal clock of the computer should be set in relation to the system time of the /MiniLinux/.
*off* = The internal clock of the computer is not changed.
*local* = The internal clock is set to local time. Preferably if, for example, there is still a native Windows installation available on the PC.
*utc* = The internal clock is set to the /Coordinated Universal Time/. This is the most common setup in a pure Linux environment.', german='Legt fest, ob und wie die interne Uhr des Rechners im Bezug auf die Systemzeit des /MiniLinux/ gesetzt werden soll.
*off* = Die interne Uhr des Rechners wird nicht verändert.
*local* = Die interne Uhr wird auf die Lokalzeit gesetzt. Bevorzugt wenn z.B. noch eine native Windows-Installation auf dem PC vorhanden ist.
*utc* = Die interne Uhr wird auf die /Koordinierte Weltzeit/ gesetzt. Dies ist die gängige Einstellung in einem reinen Linux-Umfeld.', portuguese='Especifica se e como o relógio interno do computador deve ser definido em relação ao horário do sistema do /MiniLinux/.
*off* = O relógio interno do computador não é alterado.
*local* = O relógio interno está definido para a hora local. De preferência se, por exemplo, ainda existe uma instalação Windows nativo disponível no PC.
*utc* = O relógio interno é definido para o /Tempo Universal Coordenado/. Esta é a configuração mais comum em um ambiente puramente Linux' WHERE setting='SLX_BIOS_CLOCK';

UPDATE setting SET english='Address of the NTP time server. Multiple servers can be specified separated by spaces.
The servers are queried in sequence until a responding server is found.', german='Adresse des NTP-Zeitservers. Es können mehrere Server mit Leerzeichen getrennt angegeben werden.
Die Server werden der Reihe nach angefragt, bis ein antwortender Server gefunden wird.', portuguese='Endereço do servidor de horário NTP. Vários servidores podem ser especificados separados por espaços.
Os servidores são consultados em seqüência até que um servidor respondendo for encontrado.' WHERE setting='SLX_NTP_SERVER';

UPDATE setting SET english='The root password of the basic system. Only required for diagnostic purposes on the client.
Leave field blank to disallow root logins.
/Hint/: The password is encrypted with $6$ hash, so it is no longer readable after saving!', german='Das root-Passwort des Grundsystems. Wird nur für Diagnosezwecke am Client benötigt.
Feld leer lassen, um root-Logins zu verbieten.
/Hinweis/: Das Passwort wird crypt $6$ gehasht, daher wir das Passwort nach dem Speichern nicht mehr lesbar sein!', portuguese='A senha root do sistema base. Exigido somente para fins de diagnóstico no cliente.
Deixar campo em branco para não permitir login com root.
/Dica/: A senha é criptografada com hash $6$, então se torna ilegível após ser salva!' WHERE setting='SLX_ROOT_PASS';
