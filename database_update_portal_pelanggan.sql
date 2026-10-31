-- Jalankan sekali pada database rumahcare sebelum mengaktifkan akun portal pelanggan.
ALTER TABLE pengguna
    MODIFY role ENUM('admin', 'petugas', 'pelanggan') NOT NULL,
    ADD COLUMN id_pelanggan INT(11) NULL AFTER id_petugas,
    ADD UNIQUE KEY uq_pengguna_pelanggan (id_pelanggan),
    ADD CONSTRAINT fk_pengguna_pelanggan
        FOREIGN KEY (id_pelanggan) REFERENCES pelanggan (id_pelanggan)
        ON DELETE CASCADE;
