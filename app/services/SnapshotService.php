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
        $sql = "SELECT status_name, prioridad_nivel FROM issues WHERE visible = 1";
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
            $s = strtolower(trim($row['status_name']));
            $p = (int)$row['prioridad_nivel'];

            if (isset($prio[$p])) $prio[$p]++;

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