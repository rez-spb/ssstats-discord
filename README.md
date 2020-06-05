# ssstats-discord
Custom version of SuperSeriousStats by Jos de Ruijter.  
Forked from [original GitHub repository](https://github.com/tommyrot/superseriousstats). Please see original's description and copyright.

## Reasons
I mostly needed something that will take in Discord logs (converted to IRC format), and build stats page. 
This project used PHP and had low supported version and pretty much most of the work done, so I decided to 
fork it if I succeed in adding a new parser.  

## Why so much rework
IRC had its own rules for nicknames, which were hardcoded as regex into SSS. So in order to make this work 
I had to loosen the security and rewrite all the nicknames parsing parts. Tried to make as few modifications 
as possible, but still that was incompatible with the original.  

After that I decided that I should redo the output format to allow for bigger screens than 1024x768, 
because on 1080p it looks so small with one column in the center. The column was nicely formatted and it 
was a hard decision, but people just complained about so much wasted space. While I reorganized, it became 
clear that some decisions were made in code to maintain `840px` wide width, so I reverted them. And of 
course the design will be changed mostly for my needs, and afterwards if I have time I will try to make 
it out as a separate compatible module for more customization freedom, but that's not a priority.  

## License
Most part of the code can be used under the original license of SSS. 
My reworks are LGPLv3 licenced, so use them, improve them, well, the usual.  

Rez.
