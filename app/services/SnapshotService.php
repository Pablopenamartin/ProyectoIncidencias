<?php

class SnapshotService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
            return;
        }

        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            env('DB_HOST'),
            env('DB_PORT'),
            env('DB_NAME')
        );

        $this->pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function createSnapshot(): int
    {
        // Leemos:
        // - incidencias visibles (abiertas / activas)
        // - incidencias cerradas automáticamente (cerrado_unificado), aunque visible = 0
        $sql = "
            SELECT
                status_name,
                estado_categoria,
                prioridad_nivel,
                visible
            FROM issues
            WHERE visible = 1
               OR estado_categoria = 'cerrado_unificado'
        ";

        $rows = $this->pdo->query($sql)->fetchAll();

        $est = [
            'esperando_ayuda'   => 0,
            'escalated'         => 0,
            'en_curso'          => 0,
            'pending'           => 0,
            'waiting_approval'  => 0,
            'waiting_customer'  => 0,
            'cerrado_unificado' => 0,
            'other'             => 0,
        ];

        // Prioridades P1–P5
        $prio = [1=>0,2=>0,3=>0,4=>0,5=>0];

        foreach ($rows as $row) {
            
            $s = strtolower(trim((string)$row['status_name']));
            $cat = strtolower(trim((string)($row['estado_categoria'] ?? '')));
            $p = (int)$row['prioridad_nivel'];
            $isVisible = (int)($row['visible'] ?? 0) === 1;

            // Las prioridades del panel deben seguir contando solo tickets abiertos / visibles
            if ($isVisible && isset($prio[$p])) {
                $prio[$p]++;
            }

            // Si la incidencia ya está en la categoría unificada de cerrados,
            // forzamos su conteo en CERRADOS aunque visible = 0.
            if ($cat === 'cerrado_unificado') {
                $est['cerrado_unificado']++;
                continue;
            }


            switch ($s) {
                case 'open':
                case 'abierta':
                    $est['esperando_ayuda']++; break;

                case 'escalated':
                    $est['escalated']++; break;

                case 'work in progress':
                case 'in progress':
                case 'en curso':
                    $est['en_curso']++; break;

                case 'pending':
                case 'pendiente':
                    $est['pending']++; break;

                case 'waiting for approval':
                    $est['waiting_approval']++; break;

                case 'waiting for customer':
                case 'esperando por el cliente':
                    $est['waiting_customer']++; break;

                case 'closed':
                case 'cancelled':
                case 'canceled':
                case 'completed':
                case 'completado':
                    $est['cerrado_unificado']++; break;

                default:
                    $est['other']++;
            }
        }

        $total_abiertas =
            $est['esperando_ayuda'] +
            $est['escalated'] +
            $est['en_curso'] +
            $est['pending'] +
            $est['waiting_approval'] +
            $est['waiting_customer'] +
            $est['other'];

        $sql2 = "INSERT INTO snapshots (
                created_at,
                esperando_ayuda, escalated, en_curso, pending,
                waiting_approval, waiting_customer, cerrado_unificado, other,
                p1, p2, p3, p4, p5,
                total_abiertas
        ) VALUES (
                NOW(),
                :ea, :es, :ec, :pe,
                :wa, :wc, :cu, :ot,
                :p1, :p2, :p3, :p4, :p5,
                :ta
        )";

        $st = $this->pdo->prepare($sql2);

        $st->execute([
            ':ea' => $est['esperando_ayuda'],
            ':es' => $est['escalated'],
            ':ec' => $est['en_curso'],
            ':pe' => $est['pending'],
            ':wa' => $est['waiting_approval'],
            ':wc' => $est['waiting_customer'],
            ':cu' => $est['cerrado_unificado'],
            ':ot' => $est['other'],
            ':p1' => $prio[1],
            ':p2' => $prio[2],
            ':p3' => $prio[3],
            ':p4' => $prio[4],
            ':p5' => $prio[5],
            ':ta' => $total_abiertas
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}