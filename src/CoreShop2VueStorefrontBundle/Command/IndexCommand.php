<?php

namespace CoreShop2VueStorefrontBundle\Command;

use CoreShop\Component\Core\Repository\CategoryRepositoryInterface;
use CoreShop\Component\Core\Repository\ProductRepositoryInterface;
use CoreShop\Component\Pimcore\BatchProcessing\BatchListing;
use CoreShop2VueStorefrontBundle\Bridge\ImporterFactory;
use CoreShop2VueStorefrontBundle\Bridge\ImporterInterface;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\Listing;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class IndexCommand extends AbstractCommand
{
    protected static $defaultName = 'vsbridge:index-objects';

    /**
     * @var ImporterFactory
     */
    private $importerFactory;

    protected function configure()
    {
        $this
            ->addArgument('site', InputArgument::OPTIONAL, 'Site to index')
            ->addArgument('type', InputArgument::OPTIONAL, 'Object types to index')
            ->addArgument('language', InputArgument::OPTIONAL, 'Language to index')
            ->addArgument('store', InputArgument::OPTIONAL, 'Site store to index')
            ->addOption('updated-since', 's', InputOption::VALUE_OPTIONAL, 'Fetch objects updated in the relative timeframe ("5minute", "2hour", "1day", "yesterday" etc)')
            ->setName('vsbridge:index-objects')
            ->setDescription('Indexing objects of given type in vuestorefront');
    }

    public function __construct(ImporterFactory $importerFactory)
    {
        parent::__construct(self::$defaultName);

        $this->importerFactory = $importerFactory;
        $this->repository = $repository;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $style = new SymfonyStyle($input, $output);
        $style->title('Coreshop => Vue Storefront data importer');

        $site = $input->getArgument('site');
        $type = $input->getArgument('type');
        $language = $input->getArgument('language');
        $store = $input->getArgument('store');

        $sinceDatetime = null;
        if (null !== $since = $input->getOption('updated-since')) {
            $sinceDatetime = new \Datetime();
            $sinceDatetime->modify($since);
            $controlDatetime = new \Datetime();

            if ($sinceDatetime->getTimestamp() === $controlDatetime->getTimestamp()) {
                $style->error('Invalid since param passed, try something like "-5minute", "-2hour", "-1day", "yesterday"');

                return 1;
            }

            $style->warning(sprintf('Indexing only updated since: %1$s', $sinceDatetime->format('c')));
        }

        $importers = $this->importerFactory->create($site, $type, $language, $store, $sinceDatetime);

        /** @var ImporterInterface $importer */
        foreach ($importers as $importer) {
            $style->section(sprintf('Importing: %1$s', $importer->describe()));
            $style->note(sprintf('Target: %1$s', $importer->getTarget()));

            $count = $importer->count();
            if ($count === 0) {
                $style->warning('Nothing to import, skipping.');

                continue;
            }

            $style->note(sprintf('Found %1$d items to import.', $count));
            $progressBar = $style->createProgressBar($count);
            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message:6s%');
            $importer->import(function (object $object) use ($progressBar) {
                $progressBar->setMessage(sprintf('<info>%1$s</info>', $this->describe($object)));
                $progressBar->advance();
            });
            $progressBar->clear();

            $style->success(sprintf('Imported %1$d items.', $count));
        }

        $style->success('Done.');

        return 0;
    }

    private function describe(object $object): string
    {
        $callable = [$object, 'getFullPath'];
        if (method_exists($object, 'getFullPath')) {
            return $callable();
        }

        return get_class($object);
    }
}
