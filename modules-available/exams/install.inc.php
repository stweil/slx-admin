<?php

$res = array();

$res[] = tableCreate('exams', '
    `examid` int(11) NOT NULL AUTO_INCREMENT,
    `starttime` int(11) NOT NULL,
    `endtime` int(11) NOT NULL,
    `description` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`examid`)
    ');


if (in_array(UPDATE_DONE, $res)) {
    finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
