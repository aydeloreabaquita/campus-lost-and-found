<?php
declare(strict_types=1);
final class LostFoundItem {
    private const FIELDS = ['item_name','category','item_type','description','location','date_reported','status','reporter_name'];
    public function __construct(private PDO $pdo, private int $userId) {}
    private function transaction(string $action, callable $write): void {
        $this->pdo->beginTransaction();
        try {
            $reference = $write();
            $this->pdo->prepare('INSERT INTO activity_log (user_id,action,reference_no) VALUES (?,?,?)')->execute([$this->userId,$action,$reference]);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
    public function add(array $data): void {
        $this->transaction('Added', function () use ($data) {
            $reference = 'LNF-' . strtoupper(bin2hex(random_bytes(6)));
            $values = array_map(fn($key) => $data[$key], self::FIELDS);
            $this->pdo->prepare('INSERT INTO lost_found_items (user_id,reference_no,'.implode(',',self::FIELDS).') VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$this->userId,$reference,...$values]);
            return $reference;
        });
    }
    public function find(int $id): array {
        $s=$this->pdo->prepare('SELECT * FROM lost_found_items WHERE id=? AND user_id=?');
        $s->execute([$id,$this->userId]);
        return $s->fetch() ?: throw new RuntimeException('Record not found.');
    }
    public function update(int $id, array $data): void {
        $this->transaction('Updated', function () use ($id,$data) {
            $item=$this->find($id);
            $sql='UPDATE lost_found_items SET '.implode(',',array_map(fn($k)=>$k.'=?',self::FIELDS)).' WHERE id=? AND user_id=?';
            $this->pdo->prepare($sql)->execute([...array_map(fn($k)=>$data[$k],self::FIELDS),$id,$this->userId]);
            return $item['reference_no'];
        });
    }
    public function delete(int $id): void {
        $this->transaction('Deleted', function () use ($id) {
            $item=$this->find($id);
            $this->pdo->prepare('DELETE FROM lost_found_items WHERE id=? AND user_id=?')->execute([$id,$this->userId]);
            return $item['reference_no'];
        });
    }
    public function search(string $keyword): array {
        $s=$this->pdo->prepare('SELECT * FROM lost_found_items WHERE user_id=? AND (reference_no LIKE ? OR item_name LIKE ? OR category LIKE ? OR item_type LIKE ? OR location LIKE ? OR status LIKE ? OR reporter_name LIKE ?) ORDER BY id DESC');
        $s->execute([$this->userId,...array_fill(0,7,'%'.$keyword.'%')]);
        return $s->fetchAll();
    }
    public function history(): array {
        $s=$this->pdo->prepare('SELECT * FROM activity_log WHERE user_id=? ORDER BY id DESC LIMIT 15');
        $s->execute([$this->userId]); return $s->fetchAll();
    }
}
