<?php
namespace SeanMorris\SubSpace\Idilic\View;
class Motd extends \SeanMorris\Theme\View
{
}
__halt_compiler(); ?>
Message of the Day: 

Welcome to the subspace server, <?=$name??$uid;?>!

<?php if($name): ?>You've been assined uid <?=$uid;?>.

<?php endif; ?>
Type 'commands' to get started or '?' for help.

Type 'manual' for more detailed info.

You are likely to be eaten by a grue.

--
