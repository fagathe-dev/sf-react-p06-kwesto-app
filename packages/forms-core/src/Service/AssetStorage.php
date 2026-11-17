<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stockage privé des fichiers déposés par les participants (UPLOAD_STORAGE_DIR).
 *
 * Le dossier est hors de public/ : un fichier n'est jamais servi directement par le serveur web,
 * seulement par l'API après contrôle des droits. C'est pourquoi l'UploaderService de core-php,
 * qui écrit dans public/uploads, n'est pas utilisé ici ; la validation MIME / taille, elle, reste
 * confiée à son UploaderValidationService (voir AssetService).
 */
final class AssetStorage
{
    private readonly string $rootDir;
    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire(param: 'forms_core.upload.storage_dir')]
        string $storageDir,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->filesystem = new Filesystem();
        $this->rootDir = Path::makeAbsolute(Path::canonicalize($storageDir), $projectDir);
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Déplace le fichier téléversé dans le stockage, sous un nom aléatoire.
     *
     * @return string chemin relatif au stockage, à enregistrer en base
     */
    public function store(UploadedFile $file, string $subDirectory, string $extension): string
    {
        $subDirectory = trim(Path::canonicalize($subDirectory), '/');
        $fileName = bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');

        $this->filesystem->mkdir($this->rootDir.'/'.$subDirectory, 0o750);
        $file->move($this->rootDir.'/'.$subDirectory, $fileName);

        return $subDirectory.'/'.$fileName;
    }

    /**
     * Chemin absolu d'un fichier du stockage, null s'il n'existe pas ou sort du dossier.
     */
    public function absolutePath(string $relativePath): ?string
    {
        $absolute = Path::canonicalize($this->rootDir.'/'.$relativePath);

        if (!Path::isBasePath($this->rootDir, $absolute) || !is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    /**
     * Chemin absolu d'écriture (le fichier peut ne pas exister), dossier parent créé.
     */
    public function writablePath(string $relativePath): string
    {
        $absolute = Path::canonicalize($this->rootDir.'/'.$relativePath);

        if (!Path::isBasePath($this->rootDir, $absolute)) {
            throw new \InvalidArgumentException('Chemin hors du dossier de stockage.');
        }

        $this->filesystem->mkdir(\dirname($absolute), 0o750);

        return $absolute;
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);

        if (null !== $absolute) {
            $this->filesystem->remove($absolute);
        }
    }

    /**
     * Supprime un sous-dossier du stockage et tout son contenu (suppression d'un questionnaire).
     */
    public function deleteDirectory(string $subDirectory): void
    {
        $absolute = Path::canonicalize($this->rootDir.'/'.trim($subDirectory, '/'));

        if ($absolute !== $this->rootDir && Path::isBasePath($this->rootDir, $absolute) && is_dir($absolute)) {
            $this->filesystem->remove($absolute);
        }
    }
}
