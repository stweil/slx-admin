<?php

class StatisticsStyling
{

	public static function ramColorClass($mb)
	{
		if ($mb < 1500) {
			return 'danger';
		}
		if ($mb < 2500) {
			return 'warning';
		}

		return '';
	}

	public static function kvmColorClass($state)
	{
		if ($state === 'DISABLED') {
			return 'danger';
		}
		if ($state === 'UNKNOWN' || $state === 'UNSUPPORTED') {
			return 'warning';
		}

		return '';
	}

	public static function hddColorClass($gb)
	{
		if ($gb < 7) {
			return 'danger';
		}
		if ($gb < 25) {
			return 'warning';
		}

		return '';
	}

}