FROM worker.subspace.seanmorr.is:latest
MAINTAINER Sean Morris <sean@seanmorr.is>
CMD idilic link \
	&& idilic applySchemas 1 \
	&& idilic migrate 1 \
