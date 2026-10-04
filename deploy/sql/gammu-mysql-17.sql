-- Schemat bazy Gammu SMSD w wersji 17 (Gammu 1.40–1.43, w Ubuntu 26.04: 1.42.0) dla MySQL/MariaDB.
-- Na podstawie docs/sql/mysql.sql z kodu źródłowego Gammu (https://github.com/gammu/gammu, licencja GPL-2.0).
-- Dołączony do repozytorium, bo na Macu (środowisko deweloperskie) nie ma paczki Gammu; na serwerze
-- instalator używa skryptu z paczki gammu-smsd (⚠ U4). Tabele od razu w InnoDB (rozdz. 3.2).
-- ⚠ Do porównania z plikiem z paczki przy weryfikacji U4 (etap 6).

CREATE TABLE IF NOT EXISTS `gammu` (
  `Version` integer NOT NULL DEFAULT '0' PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `gammu` (`Version`) SELECT 17 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `gammu`);

CREATE TABLE IF NOT EXISTS `inbox` (
  `UpdatedInDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ReceivingDateTime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Text` text NOT NULL,
  `SenderNumber` varchar(20) NOT NULL DEFAULT '',
  `Coding` enum('Default_No_Compression','Unicode_No_Compression','8bit','Default_Compression','Unicode_Compression') NOT NULL DEFAULT 'Default_No_Compression',
  `UDH` text NOT NULL,
  `SMSCNumber` varchar(20) NOT NULL DEFAULT '',
  `Class` integer NOT NULL DEFAULT '-1',
  `TextDecoded` text NOT NULL,
  `ID` integer unsigned NOT NULL AUTO_INCREMENT,
  `RecipientID` text NOT NULL,
  `Processed` enum('false','true') NOT NULL DEFAULT 'false',
  `Status` integer NOT NULL DEFAULT '-1',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `outbox` (
  `UpdatedInDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `InsertIntoDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SendingDateTime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SendBefore` time NOT NULL DEFAULT '23:59:59',
  `SendAfter` time NOT NULL DEFAULT '00:00:00',
  `Text` text,
  `DestinationNumber` varchar(20) NOT NULL DEFAULT '',
  `Coding` enum('Default_No_Compression','Unicode_No_Compression','8bit','Default_Compression','Unicode_Compression') NOT NULL DEFAULT 'Default_No_Compression',
  `UDH` text,
  `Class` integer DEFAULT '-1',
  `TextDecoded` text NOT NULL,
  `ID` integer unsigned NOT NULL AUTO_INCREMENT,
  `MultiPart` enum('false','true') DEFAULT 'false',
  `RelativeValidity` integer DEFAULT '-1',
  `SenderID` varchar(255),
  `SendingTimeOut` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `DeliveryReport` enum('default','yes','no') DEFAULT 'default',
  `CreatorID` text NOT NULL,
  `Retries` int(3) DEFAULT 0,
  `Priority` integer DEFAULT 0,
  `Status` enum('SendingOK','SendingOKNoReport','SendingError','DeliveryOK','DeliveryFailed','DeliveryPending','DeliveryUnknown','Error','Reserved') NOT NULL DEFAULT 'Reserved',
  `StatusCode` integer NOT NULL DEFAULT '-1',
  PRIMARY KEY (`ID`),
  KEY `outbox_date` (`SendingDateTime`, `SendingTimeOut`),
  KEY `outbox_sender` (`SenderID`(250))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `outbox_multipart` (
  `Text` text,
  `Coding` enum('Default_No_Compression','Unicode_No_Compression','8bit','Default_Compression','Unicode_Compression') NOT NULL DEFAULT 'Default_No_Compression',
  `UDH` text,
  `Class` integer DEFAULT '-1',
  `TextDecoded` text,
  `ID` integer unsigned NOT NULL DEFAULT '0',
  `SequencePosition` integer NOT NULL DEFAULT '1',
  `Status` enum('SendingOK','SendingOKNoReport','SendingError','DeliveryOK','DeliveryFailed','DeliveryPending','DeliveryUnknown','Error','Reserved') NOT NULL DEFAULT 'Reserved',
  `StatusCode` integer NOT NULL DEFAULT '-1',
  PRIMARY KEY (`ID`, `SequencePosition`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `phones` (
  `ID` text NOT NULL,
  `UpdatedInDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `InsertIntoDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `TimeOut` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Send` enum('yes','no') NOT NULL DEFAULT 'no',
  `Receive` enum('yes','no') NOT NULL DEFAULT 'no',
  `IMEI` varchar(35) NOT NULL,
  `IMSI` varchar(35) NOT NULL,
  `NetCode` varchar(10) DEFAULT 'ERROR',
  `NetName` varchar(35) DEFAULT 'ERROR',
  `Client` text NOT NULL,
  `Battery` integer NOT NULL DEFAULT -1,
  `Signal` integer NOT NULL DEFAULT -1,
  `Sent` int NOT NULL DEFAULT 0,
  `Received` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`IMEI`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sentitems` (
  `UpdatedInDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `InsertIntoDB` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `SendingDateTime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `DeliveryDateTime` timestamp NULL,
  `Text` text NOT NULL,
  `DestinationNumber` varchar(20) NOT NULL DEFAULT '',
  `Coding` enum('Default_No_Compression','Unicode_No_Compression','8bit','Default_Compression','Unicode_Compression') NOT NULL DEFAULT 'Default_No_Compression',
  `UDH` text NOT NULL,
  `SMSCNumber` varchar(20) NOT NULL DEFAULT '',
  `Class` integer NOT NULL DEFAULT '-1',
  `TextDecoded` text NOT NULL,
  `ID` integer unsigned NOT NULL DEFAULT '0',
  `SenderID` varchar(255) NOT NULL,
  `SequencePosition` integer NOT NULL DEFAULT '1',
  `Status` enum('SendingOK','SendingOKNoReport','SendingError','DeliveryOK','DeliveryFailed','DeliveryPending','DeliveryUnknown','Error') NOT NULL DEFAULT 'SendingOK',
  `StatusError` integer NOT NULL DEFAULT '-1',
  `TPMR` integer NOT NULL DEFAULT '-1',
  `RelativeValidity` integer NOT NULL DEFAULT '-1',
  `CreatorID` text NOT NULL,
  `StatusCode` integer NOT NULL DEFAULT '-1',
  PRIMARY KEY (`ID`, `SequencePosition`),
  KEY `sentitems_date` (`DeliveryDateTime`),
  KEY `sentitems_tpmr` (`TPMR`),
  KEY `sentitems_dest` (`DestinationNumber`),
  KEY `sentitems_sender` (`SenderID`(250))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
