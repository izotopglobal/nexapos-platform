-- Browser-POS security gate: a device's channel (native app vs browser
-- tab) is stamped once at register_device time and never changed again -
-- same immutable-after-creation pattern as clients.is_owner. Settlement/
-- payout endpoints in public/index.php refuse a 'browser' channel
-- outright, regardless of is_owner or role, since a browser build's
-- client-side code is inherently inspectable/patchable in a way a
-- compiled native binary isn't - see the browser-POS plan's own
-- "defense-in-depth, not cryptographically unbeatable" note.
ALTER TABLE clients
    ADD COLUMN channel ENUM('native', 'browser') NOT NULL DEFAULT 'native' AFTER is_owner;
