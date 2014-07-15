CREATE TABLE messages (
id VARCHAR(255),
message VARCHAR(255),
irc_notified TINYINT(1) NOT NULL,
primary KEY (id));

CREATE TABLE config (
key VARCHAR(255),
value VARCHAR(255),
primary KEY (key));
