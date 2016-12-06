<?php

class LocationInfo
{
  //TODO TEST WITH NEWER DB
  public function getPcState($logintime, $lastseen) {
    /*   pcState:
     *  [0] =  IDLE (NOT IN USE)
     *  [1] = OCCUPIED (IN USE)
     *  [2] = OFF
     *  [3] = 10 days offline (BROKEN?)
     */

     $NOW = time();


    if ($NOW - $lastseen > 864000 && false) { // TODO: REMOVE FALSE. TEST in testenvironment is not possible
      return 3;
    } elseif ($NOW - $lastseen > 610 && false) { // TODO: REMOVE FALSE. TEST in testenvironment is not possible
      return 2;
    } elseif ($logintime == 0) {
      return 0;
    } elseif ($logintime > 0) {
      return 1;
    }
    return -1;
  }
}
