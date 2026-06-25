# Segment-Bestzeiten — Testbericht (Spec 2026-06-24)

Stand: 2026-06-25. Quelle: `backend/GAME_SEGMENT_SPEED_BACKEND.md` §9.

| Kriterium | Soll | Ist | Test |
|-----------|------|-----|------|
| §9.1 Durchfahrtszeit (Unit) | 200 m / 30 s → 30000 ms, 24.0 km/h | grün | `EdgeRecordTest::testDurationMathFromShapeDelta` |
| §9.2 Tages-Deckel MIN | Zweite Fahrt schneller → ein Pass, 30000 ms | grün | `EdgeRecordTest::testDayCapKeepsFastestPass` |
| §9.3 All-Time-Best | MIN über Tagespässe, ein Eintrag in Top-N | grün | `EdgeRecordTest::testAllTimeBestIsMinimumAcrossDays` |
| §9.4 Bike-Klassen | muscle ≠ ebike auf derselben Kante | grün | `EdgeRecordTest::testBikeClassSeparation` |
| §9.5 Rekord-Auth | Pass ja, duration_ms NULL, Skip-Zähler | grün | `EdgeRecordTest::testRecordAuthSkipsButPassRemains` |
| §9.6 Orthogonalität | Besitz/Wert/Frische/n unverändert | grün | `EdgeRecordTest::testRecordsOrthogonalToOwnership` |
| §9.7 bike_class GPX | ebike / fehlt → other | grün | `EdgeRecordTest::testBikeClassFromGpxMetadata` |
| §9.8 Crowns / metric=records | records_held + Leaderboard | grün | `EdgeRecordTest::testCrownsAndRecordsMetric` |
| §9.9 Read | Top-N, is_me/me, anonym ohne me | grün | `EdgeRecordTest::testRecordsReadAnonymousAndAuthenticated` |
| §9.10 Idempotenz | Re-Ingest ändert Rekord nicht | grün | `EdgeRecordTest::testReIngestIdempotent` |

Lauf: `vendor/bin/phpunit tests/Integration/Game/EdgeRecordTest.php`

Legacy `SegmentSpeedTest` (game_segment_effort) ist skipped — ersetzt durch Rekorde auf `game_edge_pass`.
