<?php

return [
    // GoAopBundle must stay the first bundle: classes loaded before the aspect kernel boots can not be woven.
    // The baseline environment runs the same application without it (the aspects do not run).
    Go\Symfony\GoAopBundle\GoAopBundle::class => ['dev' => true, 'prod' => true, 'prebuilt' => true, 'test' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
];
